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

## Living Knowledge planning boundary — 2026-09-04

The current intake may also return a bounded `knowledge_enrichment` packet after
semantic target resolution. This packet is planning output only and contains
`status`, resolved `subject`, `candidates`, `diagnostics`, `proposal_ready` and
`unresolved_reasons`. It never submits, approves or applies Knowledge/Evidence
and never creates a Graph predicate.

When an already-validated explicit `about` relation is supplied, its canonical
target is authoritative for both the Video relation candidate and Knowledge
enrichment. The same Variant target must not be silently broadened to Model or
Brand by title/user-hint text matching. Without an explicit valid target, normal
read-only resolution remains fail-closed on ambiguity.

`USER_HINT` is high-value factual context but is not Evidence by itself.
Authorized transcript text is source material only; it must first yield bounded
atomic observations through an approved extractor. Whole transcript text and
generated editorial prose are never canonical Knowledge claims or Evidence.
Missing extraction is diagnostic and must not break the Video intake preview.

At the current Video boundary no canonical NHK Source is created implicitly.
A repeated observation may be classified `same_claim`; `add_evidence` is
proposal-ready only when canonical `source_id` and `source_revision` already
exist. `SOURCE_RESOLUTION_NEEDED` is therefore a valid non-mutating diagnostic.

Runtime acceptance for the Odo 36/10 probe established the required handoff:
explicit `about → variant 95873bfe-d978-4eda-a5a2-ce9ba79625df` is preserved as
the enrichment subject and candidate scope, with no Model/Brand fallback. This
acceptance does not itself create Knowledge, Evidence or Graph records.

The governed dependency runner keeps `proposal_id` separate from canonical
entity UUIDs and progresses only after Source, Claim, Evidence and Video owner
read-backs pass. `EXPLICIT_USER_RELATION` `about` still requires non-empty
`evidence_refs`; active PRIVATE/HIDDEN Evidence is verified internally and is
never made PUBLIC for that verification.
