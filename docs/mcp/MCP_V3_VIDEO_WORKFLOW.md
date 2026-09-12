# MCP V3 Video Workflow

New Video submissions use the registered Video adapter inside
`nhk.capture.ingest`, not a standalone operator writer. The Video identity and
external-reference boundary remain distinct, while Capture owns the submission
sequence and default Article draft. `nhk.video.ingest` is internal/admin
lifecycle compatibility and requires the dedicated internal capability.

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

Historical Video recovery and final projection use the same bounded editorial
enrichment seam. Its immutable context preserves specimen facts, source facts,
canonical entity context and directly related Knowledge/Entity references as
separate layers. Existing Knowledge is reused by ID; the enrichment seam has
no Knowledge/Evidence writer and never invents a claim to satisfy a content
length target. `CONTENT_COMPLETE` is a deterministic content gate distinct
from technical completeness and frontend verification. A route resolving HTTP
200 is insufficient when the content gate is `CONTENT_NEEDS_REVIEW`.

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

## Current frontend handoff — 2026-09-07

After canonical Video read-back, the public frontend query resolves Public
Identity and the canonical `/video/{slug}/` route. External YouTube URLs remain
source/provenance/embed references only. Persisted source data under
`metadata.source` is normalized in the query/application layer alongside any
approved compatibility shape; no second source record or semantic writer is
created. “Xem trên web” and “Mở nguồn gốc” remain separate Admin actions.

When a Video note names a visually explainable feature, the workflow records a
VisualSupportRequirement through existing application orchestration. Normal
input remains `nhk.capture.ingest`; no direct requirement writer is exposed.
Canonical Media read-back performs bounded reverse reconciliation and
invalidates affected Video/Knowledge/Article projections. Thumbnail/source
identity alone never satisfies technical visual support or creates Evidence.
