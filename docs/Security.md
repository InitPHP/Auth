# Security

This page documents the threat model the package was designed against,
the guarantees the shipped adapters make, and the operational practices
you should adopt around them.

It is **not** a full web-security primer. Pair it with the
[OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)
and the [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).

## Threat model

The package's adapters defend against:

| Threat | Mitigation |
| --- | --- |
| Cookie tampering | HMAC-SHA256 over the JSON payload; verified with `hash_equals()` **before** decoding. |
| Replay of a tampered cookie | Same — any byte flipped anywhere in the payload invalidates the signature. |
| Object-injection via `unserialize()` | The decoder uses `json_decode()`, which cannot instantiate objects. There is no `unserialize()` path on attacker-controlled bytes. |
| Timing oracle on the signature | `hash_equals()` is constant-time over equal-length strings. |
| Length-extension attacks on the signature | HMAC-SHA256 is provably safe under length-extension. The hand-rolled v1 scheme was not. |
| Forced session fixation through a manually written cookie | A foreign cookie does not carry the server's HMAC and is dropped on construction. |
| Browser refusing to delete an old cookie | `destroy()` reuses the original `path`/`domain`/`samesite`/`secure` so the deletion `Set-Cookie` matches the original. |

The package does **not** address (because they live outside the
storage layer):

- **CSRF** — store a CSRF token in a separate segment and validate it
  on state-changing requests. See [Recipe → CSRF
  Token](Recipe-Csrf-Token.md).
- **XSS / cookie theft via DOM access** — the cookie is `HttpOnly` by
  default; keep that on and harden your CSP separately.
- **Password storage** — use `password_hash()` /
  `password_verify()`. See the [Custom Adapters](Custom-Adapters.md)
  page.
- **Session-id rotation after login** — call `session_regenerate_id(true)`
  in your application code right after a successful auth check.
- **Transport security** — use HTTPS. `secure=true` is the v2 default
  but it relies on you actually terminating TLS.
- **Brute-force / credential-stuffing** — rate-limit at your edge or
  application layer.

## The cookie format, in one paragraph

The cookie value is `base64url(json_encode($data)) . "." . hash_hmac('sha256', $json, $salt)`.
The signature is verified with `hash_equals()` **before** the JSON is
decoded. Any failure (missing cookie, malformed format, bad signature,
invalid JSON, non-array root) yields an empty bag; the user is issued a
fresh cookie on their next write. There is no decode path that would
risk running `unserialize()` against attacker-controlled bytes.

For the full breakdown, including the legacy v1 format and why it was
replaced, see [Cookie Adapter → Wire format](Cookie-Adapter.md#wire-format).

## The cookie is signed, not encrypted

The HMAC scheme guarantees **integrity** (no attacker can produce a
cookie the server will accept) and **authenticity** (only the holder of
the salt can produce a valid cookie). It does **not** guarantee
**confidentiality** — anyone who intercepts the cookie, or who reads
the user's browser storage, can decode the JSON payload directly.

Practical consequence: put a user id, a role, a CSRF token. **Do not
put** a password hash, an API key, or any other secret. If you need
confidentiality, layer an authenticated encryption scheme over the JSON
before signing — but at that point you almost certainly want a
[server-side adapter](Session-Adapter.md) instead.

## Salt management

The salt is the HMAC key. Treat it as you would any other secret.

### Generating

```php
echo bin2hex(random_bytes(32)), PHP_EOL;
// 64 hex characters, 32 bytes of entropy
```

Store the result in your environment, secrets manager, or config file.
Never commit it.

### Salt length

The constructor requires at least 32 bytes (`CookieAdapter::MIN_SALT_LENGTH`).
SHA-256 produces 32-byte digests; a key shorter than the digest is the
classic shape of "I do not understand HMAC". The 32-byte minimum is a
sanity floor, not a maximum — longer keys are accepted and welcomed.

### Salt rotation

Rotating the salt invalidates every existing cookie. There is no
"sign with old, accept either" path in the shipped adapter, so a
rotation is observable to every user as a forced logout.

Operational playbook:

1. Generate the new salt.
2. Pick a maintenance window where forced logout is acceptable
   (overnight, deploy boundary, weekend).
3. Roll the application config with the new salt.
4. Watch your error logs for a brief spike of "user re-logged in".

If forced logout is unacceptable, you have two pragmatic options:

- **Run two segments side by side.** Read both segments at the start
  of every request; write only to the new segment. After your cookie
  TTL has elapsed, retire the old segment.
- **Switch to a server-side adapter** where you can manage rotation
  centrally without affecting client cookies.

### Per-application salts

Use a different salt per application. A leaked salt in one app must
not let an attacker forge cookies for another.

If you operate a fleet of similar apps (multi-tenant SaaS, regional
deployments), derive each app's salt from a master key plus an
app-specific tag with HKDF or HMAC. The library does not ship a
helper for this — call `hash_hmac()` yourself.

## Session security checklist

For [`SessionAdapter`](Session-Adapter.md):

- [ ] `session_start()` runs before the adapter is constructed.
- [ ] `session_regenerate_id(true)` runs immediately after a
      successful login (or any privilege escalation).
- [ ] `session.cookie_secure = 1` in `php.ini` for production.
- [ ] `session.cookie_httponly = 1` in `php.ini`.
- [ ] `session.cookie_samesite = "Lax"` (or `"Strict"`, if your app
      tolerates it) in `php.ini`.
- [ ] `session.use_strict_mode = 1` to reject attacker-supplied session
      ids.
- [ ] A persistence backend appropriate for your scale (the default
      file handler is fine for single-host setups; Redis / Memcached /
      database for multi-host).
- [ ] A finite `session.gc_maxlifetime`.

## Cookie security checklist

For [`CookieAdapter`](Cookie-Adapter.md):

- [ ] Salt is at least 32 bytes and loaded from secrets, not
      committed.
- [ ] `secure = true` (the v2 default) unless you have an explicit,
      documented reason to disable it.
- [ ] `httponly = true` (the v2 default).
- [ ] `samesite = 'Lax'` or `'Strict'` (the v2 default is `'Lax'`).
- [ ] `expires` is short enough that a stolen cookie has a small blast
      radius. Plain auth: a few hours. "Remember me": 30 days, paired
      with a server-side revocation list. See
      [Recipe → Remember-Me](Recipe-Remember-Me.md).
- [ ] Cookies are scoped to the right `path` and `domain`. A cookie set
      on `example.com` reaches **every** subdomain when `domain` is
      empty; restrict it if that is more than you need.
- [ ] No secrets in the payload (only ids, roles, tokens).

## Logging and observability

The package does not log. It throws.

That is deliberate — every failure mode is something the application
needs to handle explicitly:

- `InvalidArgumentException` at startup is a configuration bug;
  surface it loudly.
- `RuntimeException` from a destroyed adapter is a programming bug;
  surface it loudly.
- A tampered cookie yields an empty bag with no exception, because
  the legitimate user-facing outcome is "you're not logged in, please
  sign in again". If you want to detect this case for security
  monitoring, instrument it at the call site:

  ```php
  $auth = Segment::cookie('auth', ['salt' => $secret]);
  if (isset($_COOKIE['auth']) && !$auth->has('user_id')) {
      logger()->warning('Auth cookie present but rejected', [
          'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
          'ua'        => $_SERVER['HTTP_USER_AGENT'] ?? null,
      ]);
  }
  ```

  Repeat-offender IPs in that log are worth attention.

## Reporting a vulnerability

Use **GitHub Private Vulnerability Reporting** from the
[Security tab](https://github.com/InitPHP/Auth/security/advisories/new)
of the repository, or email the address in the
[org-wide SECURITY.md](https://github.com/InitPHP/.github/blob/main/SECURITY.md).

Do **not** open a public issue or Discussion for a vulnerability.

## Where to go next

- [Cookie Adapter](Cookie-Adapter.md) — the implementation details.
- [Migration Guide](Migration-Guide.md) — what hardening changed
  between v1 and v2.
- [Recipe → CSRF Token](Recipe-Csrf-Token.md) — the companion pattern
  for state-changing requests.
- [Recipe → Remember-Me](Recipe-Remember-Me.md) — long-lived cookies
  with revocation.
