# ChatGPT file transport gateway handoff

The ChatGPT connector file path is transport-only. File-enabled calls are
materialized at the connector boundary and then converge on the existing
native file bag, Ability validation, MCP transport and `nhk.capture.ingest`
path. Text-only calls remain on the existing compatibility path.

The gateway accepts only the declared `files` field and the official file
object fields: `download_url`, `file_id`, optional `mime_type` and
`file_name`. `file_id` is never treated as a filesystem path. The runtime
allowlist is supplied by the existing `nhk_chatgpt_file_allowed_hosts`
filter; an empty allowlist fails closed.

Downloads require HTTPS, a trusted allowed host, public-address checks,
per-hop redirect revalidation, bounded streaming and timeouts. The gateway
limits a call to 20 files and 50 MB total, sniffs the materialized MIME type,
preserves order and `files[i]`/`items[i]` alignment, and cleans up temporary
files on every exit path. Signed URLs are not persisted or written to logs.

Verification for this handoff: focused gateway tests, PHP lint, diff checks and
scope-limited secret review are required before commit. WordPress-backed
integration remains environment-dependent on the exact `nhk_v3_test` runtime;
its absence is reported as an environment block rather than a gateway pass.

No Capture, Media, Governance, Graph, Authority or Article semantics are
defined or changed here. No writer fallback, base64 transport or live action
is part of this handoff.
