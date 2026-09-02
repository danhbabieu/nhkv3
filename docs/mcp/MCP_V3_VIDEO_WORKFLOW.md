# MCP V3 Video Workflow

`nhk.video.ingest` is the one-shot governed adapter for YouTube intake. It
accepts a URL and short user hint, then returns the source snapshot, duplicate
mode, NHK editorial package, Hub classification, semantic attachment
candidates, SEO projection, completeness warnings and unresolved targets beside
one DRAFT Proposal.

The handler may acquire official API metadata when `NHK_YOUTUBE_API_KEY` is
configured. Without it, source identity remains valid but metadata availability
is explicit; no fabricated facts or transcript are produced. Internal NHK
lookup is read-only and resolves canonical identities without creating Brand,
Model or other entities.

The Proposal lifecycle remains submit → human approve → eligibility →
Controlled Apply. Every Video Apply requires approved Graph attachments and
creates them atomically; no `wp_create_post`, taxonomy, post meta or direct SQL
path is used. Same idempotency key and same intent return the original
Proposal; changed intent under the same key is an idempotency conflict.

Source synchronization is read-only preview/reconciliation planning until a
separate sync command is exposed. It reports `NO_CHANGE`, `SOURCE_CHANGED`,
`SOURCE_UNAVAILABLE` or `REVIEW_REQUIRED` and never overwrites NHK editorial
content or semantic relations silently.
