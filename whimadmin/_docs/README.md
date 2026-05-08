# WhimAdmin internal docs

Audience: developers reading or extending the WhimAdmin codebase.
For operator-facing install instructions see the project root
[`README.md`](../README.md).

| Document | Topic |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Subsystems, boot lifecycle, request flow, storage zones, reuse from the WhimCMS core |
| [SECURITY.md](SECURITY.md) | Threat model, defence layers, audit log vocabulary, hardening checklist |
| [BLOCK_SCHEMAS.md](BLOCK_SCHEMAS.md) | Sidecar JSON format for the page editor's form-renderer, type vocabulary, adding a new field type |
| [EXTENDING.md](EXTENDING.md) | Adding a new block type, a new field type, a new authed route, a new audit event |

WhimAdmin sits next to the WhimCMS core and reuses its security
primitives (HMAC secret, mail transport, template engine,
RateLimiter, RequestSecurity). It never modifies the core; the
"WhimCMS core is read-only" rule is structural — every consumed
class is imported via PSR-4 and called as a value-producing service.
