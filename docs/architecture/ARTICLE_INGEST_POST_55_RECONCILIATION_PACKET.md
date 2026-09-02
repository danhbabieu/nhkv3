# Post 55 Article Reconciliation Packet

**Status:** READY FOR HUMAN REVIEW — NOT APPLIED

## Scope

This packet is the production-readiness handoff for the approved Phase 1
reconcile-only Article Ingest operation. It targets the runtime WordPress
endpoint `wp_post:<runtime_blog_id>:55`; the blog ID must be resolved at runtime
and must not be hard-coded.

The operation is explicitly not an editorial create, update, draft, publish,
replacement, slug change or body migration. No production Post, semantic row,
Graph edge, taxonomy, post meta or legacy article body has been changed by
preparation of this packet.

## Required pre-apply evidence

Before any human-approved apply, an operator must capture and compare the
read-only editorial token for Post 55: post ID, title, body, excerpt, status,
slug/permalink and revision state. The semantic bundle must be resolved again
through the live registries and must contain only claims/relations supported by
the approved Source/Evidence contract. Unknown, ambiguous, stale or duplicate-
risk inputs must fail closed.

The execution must use the coordinated `nhk.article.preflight` then
`nhk.article.ingest` surface with one stable idempotency key. All semantic
mutations, if any, must be Proposal → Human Approval → Eligibility →
Controlled Apply → repository → audit. A direct Graph or migration path is
not authorized.

## Acceptance checks

- Post ID remains `55` and the runtime blog ID is unchanged.
- Status, title, body, excerpt, slug/permalink and revision state are unchanged.
- No new WordPress Post exists as a side effect.
- Existing semantic identities and active Graph triples are reused; no duplicate
  record or relation is created.
- Every applied child proposal and dependency is present in the durable receipt.
- Semantic and WordPress read-back verification passes.
- Any partial apply remains recorded and is resumed only for missing children;
  no compensating semantic mutation is attempted.

## Human gate

This packet does not authorize production execution. A human operator must
review the live preflight, approve any semantic proposals through the existing
Governance flow, and separately authorize the final ingest call. Stop if the
target is not the expected published Post 55, if editorial preservation is not
provable, or if a registry/provenance/revision/dependency check is unavailable.

## Phase 2 blockers

WordPress editorial create/update remains deferred pending the separate
`WORDPRESS_EDITORIAL_WRITE_IDEMPOTENCY_AND_CAS` review. The missing proof is a
WordPress-side exactly-once create boundary across the crash window
`receipt reserved → wp_insert_post succeeds → process crashes`, plus an atomic
revision-bound compare-and-swap contract for editorial writes. No slug,
`import_id`, post meta, taxonomy, title or checksum may be selected as an
unapproved idempotency bridge.
