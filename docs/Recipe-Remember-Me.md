# Recipe: Remember-Me Cookies

A "remember me" cookie lets a returning user skip the password prompt.
The trade-off is that the cookie outlives the browser session, so a
stolen device or an exfiltrated cookie has a long window of misuse.

This recipe pairs `CookieAdapter` with a server-side revocation list so
you keep the convenience without losing the ability to log the user out
remotely.

## Goal

- On a successful login, optionally issue a long-lived "remember me"
  cookie alongside the short-lived session.
- On every request, if the session is gone but the remember-me cookie
  is present and unrevoked, restore the auth segment.
- On logout (or password change), revoke the remember-me cookie
  server-side so it cannot be replayed.

## Schema for the revocation list

The cookie carries an opaque token; the server keeps a row per issued
token so it can be looked up and revoked.

```sql
CREATE TABLE remember_tokens (
    token_id    CHAR(64)       NOT NULL PRIMARY KEY,  -- hash of the cookie token
    user_id     BIGINT UNSIGNED NOT NULL,
    issued_at   DATETIME       NOT NULL,
    expires_at  DATETIME       NOT NULL,
    revoked_at  DATETIME       NULL,
    user_agent  VARCHAR(255)   NULL,                  -- optional, for the user's "active sessions" UI
    INDEX (user_id)
);
```

> Storing a hash of the token (not the token itself) follows the same
> reasoning as `password_hash()`: a database breach must not give the
> attacker live cookies. The cookie itself is signed by `CookieAdapter`,
> but the hash is an extra defensive layer.

## Issuing the cookie on login

```php
<?php

declare(strict_types=1);

use InitPHP\Auth\Segment;

function issueRememberCookie(\PDO $pdo, int $userId): void
{
    $remember = Segment::cookie('remember', [
        'salt'    => $_ENV['REMEMBER_COOKIE_SECRET'],   // a SEPARATE secret from the auth cookie
        'expires' => time() + 60 * 60 * 24 * 30,        // 30 days
        'path'    => '/',
    ]);

    $token   = bin2hex(random_bytes(32));
    $tokenId = hash('sha256', $token);

    $pdo->prepare('
        INSERT INTO remember_tokens (token_id, user_id, issued_at, expires_at, user_agent)
        VALUES (:tid, :uid, NOW(), :exp, :ua)
    ')->execute([
        ':tid' => $tokenId,
        ':uid' => $userId,
        ':exp' => date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 30),
        ':ua'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);

    $remember->set('token', $token);
}
```

Two things to notice:

- The `salt` for the remember cookie is **different** from the auth
  cookie's salt. A leak of one secret must not compromise the other.
- Only the `token` goes into the cookie — never the user id directly.
  The id comes from the database lookup, which gives the server a
  chance to reject revoked or expired tokens.

## Restoring the session on a returning request

```php
<?php

use InitPHP\Auth\Segment;

session_start();

$auth     = Segment::session('auth');
$remember = Segment::cookie('remember', [
    'salt' => $_ENV['REMEMBER_COOKIE_SECRET'],
]);

if (!$auth->has('user_id') && $remember->has('token')) {
    $token   = $remember->get('token');
    $tokenId = hash('sha256', $token);

    $stmt = $pdo->prepare('
        SELECT user_id
          FROM remember_tokens
         WHERE token_id    = :tid
           AND revoked_at IS NULL
           AND expires_at  > NOW()
         LIMIT 1
    ');
    $stmt->execute([':tid' => $tokenId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row !== false) {
        $auth->set('user_id', (int) $row['user_id']);
        session_regenerate_id(true);   // defend against fixation
    } else {
        // Token was revoked or expired — drop the cookie so we don't
        // hit the database on every subsequent request.
        $remember->destroy();
    }
}
```

The two-step pattern (auth session first, remember cookie as a
fallback) keeps the database lookup off the hot path. Once the auth
segment is populated, every subsequent request reads from the session
without touching the database.

## Logout

A regular logout should also clear the remember-me cookie *and* the
database row, otherwise the next request restores the session:

```php
function logout(\PDO $pdo): void
{
    $auth     = Segment::session('auth');
    $remember = Segment::cookie('remember', [
        'salt' => $_ENV['REMEMBER_COOKIE_SECRET'],
    ]);

    if ($remember->has('token')) {
        $tokenId = hash('sha256', $remember->get('token'));
        $pdo->prepare('UPDATE remember_tokens SET revoked_at = NOW() WHERE token_id = :tid')
            ->execute([':tid' => $tokenId]);
    }

    $remember->destroy();
    $auth->destroy();
    session_regenerate_id(true);
}
```

## Revoking everywhere — "log me out of every session"

A user changing their password (or hitting a "log out everywhere"
button) should invalidate every issued token:

```php
function revokeAllRememberTokens(\PDO $pdo, int $userId): void
{
    $pdo->prepare('
        UPDATE remember_tokens
           SET revoked_at = NOW()
         WHERE user_id    = :uid
           AND revoked_at IS NULL
    ')->execute([':uid' => $userId]);
}
```

The next time any of those devices tries to use its remember cookie,
the database lookup returns no row and the cookie is dropped.

## Why a separate cookie?

You could put the remember-me state inside the regular `auth` cookie
by setting its `expires` to 30 days. Two reasons not to:

1. **Different security profiles.** The auth cookie should expire
   when the session does (a few hours, maybe a day) so a stolen
   cookie has limited blast radius. The remember-me cookie is by
   nature long-lived; segregating it lets you set tighter defaults
   on the auth cookie.
2. **Independent revocation.** Logging out of the current browser
   should not invalidate every other "remembered" device.

## Operational notes

- **Token entropy.** 32 bytes (`random_bytes(32)`) gives 256 bits of
  entropy — orders of magnitude more than a brute-forcer can sweep.
  Do not go shorter.
- **TTL.** 30 days is a common ceiling. Longer means more user
  convenience and more risk; shorter means the user re-authenticates
  more often. Pick what your threat model tolerates.
- **Storage growth.** The revocation table grows over time. Run a
  weekly job to delete rows where `expires_at < NOW() - INTERVAL 30 DAY`.
- **`Secure; HttpOnly; SameSite=Lax`** are the defaults you want.
  `CookieAdapter` provides them out of the box. Do not weaken them
  for a "remember me" cookie — the long lifetime is the
  compensation, not an excuse to drop other defences.
- **User-visible session list.** With `user_agent` and `issued_at` in
  the table, you can render a "Your active sessions" page in
  account settings. That is much better UX than blanket "log me out
  everywhere".

## Common mistakes

- **Storing the token plaintext in the database.** A database leak
  hands the attacker live cookies. Hash with SHA-256 on the server
  before storage; compare hashes on lookup.
- **Reusing the auth-cookie salt for the remember cookie.** Use a
  different secret per cookie purpose so a leak does not cascade.
- **Skipping `session_regenerate_id(true)` after restoring from a
  remember-me cookie.** A pre-restoration session id captured by an
  attacker would otherwise carry into the authenticated session.
- **Single-shot tokens (a.k.a. rotating tokens).** The OWASP
  remember-me cheat sheet recommends rotating the cookie value on
  every use. That is more secure but adds complexity (every request
  is a write); decide whether your threat model needs it. The recipe
  above does not rotate; it relies on the revocation list instead.

## Where to go next

- [Cookie Adapter](Cookie-Adapter.md) — the underlying signed-cookie
  store.
- [Security](Security.md) — broader operational concerns.
- [Recipe → Multi-Segment](Recipe-Multi-Segment.md) — combining
  remember-me alongside auth + cart + csrf.
