# Protected runtime storage

This entire directory is denied over HTTP by both Apache rules and the local development router.

- `cache/` stores the short-lived Google JWKS cache.
- `logs/` is reserved for non-public operational logs that must never contain credentials, Google tokens, or session data.
- `uploads/campaign/` stores validated JPEG/PNG/WebP campaign-context images under generated names. Files are mode 0600 where supported and are streamed only after owner/public/unlisted-token authorization.

Only `.gitkeep` placeholders are tracked. Runtime contents are ignored.

Removing a context item deletes its stored image. Failed database persistence cleans up a newly moved file. Permanently deleting an Insight cascades through its database-owned records and removes every campaign-context image attached to it.
