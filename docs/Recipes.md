# Recipes

End-to-end patterns built on `initphp/auth`. Each recipe is
self-contained, runs as-is, and explains the *why* alongside the *how*.

## When to use a recipe

The [Core Types](Permissions.md) and [Adapters](Session-Adapter.md) pages
document the building blocks in isolation. Reach for a recipe when:

- You need a concrete answer to "how do I combine these pieces?"
- You want the patterns the maintainers reach for in their own apps.
- You are porting a v1 example and want the v2-safe version.

## Available recipes

| Recipe | Use when |
| --- | --- |
| [Multi-Segment Request](Recipe-Multi-Segment.md) | A request needs to manage auth state, cart state, and a CSRF token together — each with its own storage backend. |
| [HTTP Basic Auth](Recipe-Basic-Auth.md) | You want the v1 `BasicAuthAdapter` example rewritten without SQL injection and `md5()` password hashing. Full request lifecycle around a custom adapter. |
| [CSRF Token Store](Recipe-Csrf-Token.md) | You need a per-session CSRF token that rotates on login and validates state-changing requests. |
| [Remember-Me Cookies](Recipe-Remember-Me.md) | You want long-lived "remember me" cookies that survive session expiry, with a server-side revocation list. |

## Recipe authorship

Each recipe is structured the same way:

1. **Goal** — what problem the recipe solves and when to reach for it.
2. **Schema / data model** — if the recipe touches a database.
3. **Working example** — the full code path, copy-paste ready.
4. **How the pieces fit** — narrative of the moving parts.
5. **Operational notes** — what to monitor / rotate / time out.
6. **Common mistakes** — failure modes the author has seen.

If you have a recurring auth pattern that is not yet documented, the
maintainers would love a PR — see the
[Contributing Guide](https://github.com/InitPHP/.github/blob/main/CONTRIBUTING.md).

## Related reading

- [Permissions](Permissions.md) — the helper used in most recipes for
  role gating.
- [Cookie Adapter → Wire format](Cookie-Adapter.md#wire-format) —
  background for the cookie-heavy recipes.
- [Security](Security.md) — operational concerns that apply across
  every recipe.
