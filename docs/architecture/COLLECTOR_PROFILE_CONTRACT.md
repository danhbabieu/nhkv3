# Collector Profile and Facet Maintenance Contract

Status: current executable contract, subordinate to the NHK V3 Constitution.
This document defines the Collector projection and its existing-data maintenance
boundary; it does not create an Authority type, Knowledge field, Graph predicate,
Article flow or Capture submission.

## Ownership and registry

`CollectorFacetRegistry` is the single executable vocabulary for Collector
facets. The registry owns the exact allowed values and the unresolved mapping;
Collector Profile queries and maintenance validation must consume it. The
canonical semantic owners remain Authority, Knowledge, Source/Evidence, Graph
and Governance.

The only allowed facet values are:

`display_form`, `dimensions`, `dating`, `case_styles`, `motifs`, `materials`,
`craft_modes`, `production_scale`, `movement_family`, `running_duration`,
`drive_system`, `functions`, `sound`, `music`, `automata`, `night_shutoff`,
`condition_guidance`, `originality_guidance`, `provenance`, `rarity`,
`origin_certification`.

The persisted key is `provenance.metadata.collector_facet`. `automata` is valid
only for `model`, `variant` or `specimen_observation` scope. Existing legacy
facet aliases are mapped only by the registry. Missing, malformed, unknown,
scope-invalid, stale or semantically ambiguous values resolve to `unresolved`;
there is no keyword fallback or invented facet.

## Existing-data maintenance operation

The registered Governance operation is `knowledge + collector_facet_update`.
It is an internal/admin maintenance capability, not Capture and not a new
submission path. Its governed sequence is:

`Proposal → Submit/approval policy → Eligibility → Controlled Apply →
KnowledgeService/canonical repository owner → audit → canonical read-back`.

Every proposal binds the existing Knowledge UUID, expected Knowledge revision,
Classification branch UUID and dependency revision, stable key, claim-text
hash, claim type, scope, complete provenance hash, payload fingerprint and
idempotency key. The payload permits only the registered `collector_facet`
metadata field. UUID, stable key, claim text, claim type, scope, provenance
outside that field, relations and active state are immutable through this
operation.

The maintenance CLI defaults to a read-only dry-run. `--apply` is never implied;
an apply requires an already approved proposal and the same optimistic-lock and
eligibility checks. Existing valid facets and unresolved/ambiguous records are
no-ops. Batch results are independent, stale records fail closed, and replay of
an applied proposal is idempotent. The operation cannot create Knowledge,
Article/Post drafts, Graph edges, Source/Evidence or publication state.
