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

The repository currently has bounded readers for Dictionary, Knowledge and
Authority. Several other canonical repositories expose only list or
owner-scoped reads, so a live all-owner run must report those owners as
`AUDIT_MODEL_GAP` until a bounded page boundary is supplied. No schema
migration is required for this audit capability.
