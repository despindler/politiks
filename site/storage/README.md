# Protected runtime storage

This entire directory is denied over HTTP by both Apache rules and the local development router.

- `cache/` stores the short-lived Google JWKS cache.
- `logs/` is reserved for non-public operational logs that must never contain credentials, Google tokens, or session data.
- `uploads/` is reserved for validated, non-executable campaign-context images in Milestone 9.

Only `.gitkeep` placeholders are tracked. Runtime contents are ignored.
