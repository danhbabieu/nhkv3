# System-wide existing duplicate audit

This is a read-only audit boundary introduced after the duplicate-prevention
baseline `f44648593ef9610bc2671f8a574f249df64cfe69`.

`SystemWideDuplicateAuditCoordinator` only orchestrates owner readers. It does
not compare records across owners, call lifecycle writers, persist findings, or
apply reconciliation candidates. Every owner reader must provide a bounded,
stable-cursor page. A missing page reader is reported as `BLOCKED` with
`AUDIT_MODEL_GAP`; an unbounded `list()` fallback is deliberately forbidden.

Dictionary is adapted from the existing `DictionaryDuplicateCandidateAudit`.
Its normalized-form and context decisions remain the canonical Dictionary
implementation. Knowledge, Authority, Source, Evidence, Graph, Article,
Media, MediaAsset, MediaUsage and Video have explicit owner-specific signals
in the coordinator. The output uses one cluster shape:

`owner`, `cluster_id`, `classification`, `confidence_class`, `canonical_ids`,
`active_states`, `revisions`, `identity_signals`, `conflicting_signals`,
`reasons`, `recommended_review_action`, and `cursor_page_provenance`.

`reconciliation_candidates` is a planning-only projection with `apply=false`.
It is not a Governance proposal and has no execution method.

## Runtime completeness

The current implementation supplies bounded readers for every non-Dictionary
owner in `SystemWideDuplicateAuditCoordinator::OWNERS`: Authority, Knowledge,
Source, Evidence, Graph, Article, Media, MediaAsset, MediaUsage and Video.
Dictionary remains on its existing `DictionaryDuplicateCandidateAudit` adapter
and is not rewritten by this boundary. Each SQL reader uses an ascending native
row identity cursor, reads at most 200 rows per page, and selects one lookahead
row to determine `next_cursor`.

The coordinator wraps page state in a versioned, HMAC-protected cursor using the
existing WordPress server salt. The cursor binds owner, `include_retired`, scan
policy/version, reader position and scanned count; carry rows are accepted only
after signature and shape validation. Malformed, cross-owner, filter-mismatched
or tampered cursors fail closed with `AUDIT_CURSOR_INVALID`. The carry set is
bounded to 128 projected rows. The audit stops at 5,000 scanned rows per owner
and returns `PARTIAL` with `AUDIT_MAX_SCAN_BOUND_REACHED`; it never claims
`COMPLETE` while unexhausted rows remain. `include_retired=false` is applied in
the reader query for owners with a lifecycle state; Article excludes `trash`
and `auto-draft`, and MediaUsage excludes retired usage slots.

Article remains a WordPress editorial owner, not a semantic owner. The bounded
reader may project only persisted canonical data: the `_nhk_editorial_intent`
metadata already used by the Article research inventory and active Graph
`wp_post → about → canonical endpoint` bindings, including relation-context
scope when that context exists. It never derives identity from title, body,
Evidence, Source or Graph reachability. Rows are classified as
`AUDITABLE`, `LEGACY_UNRESOLVED` (no active binding, retired-only binding or
ambiguous active subject) or `MODEL_GAP` (a binding exists but a required
identity field such as scope or continuation lineage is not durably persisted).
Only auditable rows enter duplicate grouping; legacy coverage returns `PARTIAL`
with bounded diagnostics, while a model gap remains `BLOCKED` with
`AUDIT_MODEL_GAP`. No Article semantic owner or undocumented post-meta key is
created by the audit.

The read-only MCP surface is `nhk.system-wide.duplicate-audit`, capability
gated by `nhk_view_governance`. It accepts an optional owner, opaque cursor,
bounded limit and retired-row flag, and returns owner status, counts, clusters,
next cursor and completeness. It has no mutation, apply, repair, merge, retire,
delete, rekey or migration path. No schema migration is required for this
audit capability.

## Knowledge identity V2 audit law — 2026-10-07

Knowledge audit rows use the same canonical Claim identity boundary as
pre-create, enrichment and reconciliation. Resolved rows expose the identity
policy, status and fingerprint in `identity_signals`; equality is asserted
only for two `RESOLVED` packets with the same fingerprint. `UNRESOLVED` and
`CONFLICTING` rows are excluded from duplicate clusters and reported through
bounded identity-coverage diagnostics (`identity_resolved_rows`,
`identity_unresolved_rows`, `identity_conflicting_rows`, and
`bounded_identity_review_samples`). They are never classified as equivalent.
Wording or locator similarity cannot turn them into a duplicate. Exhausting
the reader may still return `COMPLETE`, but that status does not imply full
semantic identity coverage.
Video provenance compares the registered `platform + external_video_id`
referent and subject, not wording or a canonical owner UUID.

The audit remains strictly read-only (`apply=false`). It creates no Governance
proposal, retirement, reactivation, merge, rekey or repair action. Any future
retirement must re-read and bind the current policy version, identity status
and fingerprint, Claim revisions, dependency topology/fingerprint and
Evidence lifecycle. Stale bindings, possible duplicates, unresolved/conflicting
identity and `KNOWLEDGE_REPAIR_DEPENDENCY_REVIEW_REQUIRED` remain fail-closed.
