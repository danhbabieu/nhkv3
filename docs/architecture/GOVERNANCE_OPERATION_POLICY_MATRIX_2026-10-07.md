# Governance Operation Policy Matrix — 2026-10-07

Scope is generic Governance policy only. This matrix is read-only evidence for
the consolidation at baseline `80540d26a40d5f9af7e7d88d567a00c932b08b58`.
It authorizes no proposal, staging, production or semantic data mutation.

Legend: `Y` means explicitly supported/allowed, `N` means explicitly denied,
and `—` means the operation is not applicable. `ZERO` is create/ingest
revision zero, `CURRENT_REQUIRED` is optimistic current-revision CAS, and
`NONE` means the operation carries its own special revision contract.

## Canonical operation entries

| Owner/entity types | Operation | Executor/registry | Family | Lifecycle | Revision | Capture scope | Staging | Production | Generic capability | Owner-specific preconditions |
|---|---|---:|---|---|---|---:|---:|---:|---|---|
| Authority: brand, model, variant, movement, music, component, classification, specimen, product | create | Y | governed_authority_plan | CREATE | ZERO | Y | Y | Y | — | canonical type, stable identity and Authority plan binding |
| Authority: same | ingest | Y | governed_authority_plan | CREATE | ZERO | Y | N | Y | — | internal compatibility only; no current Capture owner flow |
| Authority: same | rekey | Y | governed_authority_plan | MUTATE_EXISTING | CURRENT_REQUIRED | Y | Y | Y | — | semantic rekey policy and exact UUID/revision |
| Authority: same | merge | Y | governed_authority_plan | SPECIAL | NONE | N | N | Y | — | two-owner merge policy, identity ambiguity and explicit approval |
| Authority: same | rename | Y | governed_authority_plan | MUTATE_EXISTING | CURRENT_REQUIRED | Y | Y | Y | — | canonical name/alias policy |
| Authority: same | update | Y | governed_authority_plan | MUTATE_EXISTING | CURRENT_REQUIRED | Y | Y | Y | — | entity profile and identity policy |
| Authority: same | retire | Y | governed_authority_plan | RETIRE | CURRENT_REQUIRED | Y | Y | Y | — | owner lifecycle and dependent-publication checks |
| Authority: same | reactivate | Y | governed_authority_plan | REACTIVATE | CURRENT_REQUIRED | Y | Y | Y | — | owner lifecycle and readiness checks |
| Knowledge | create, ingest | Y | knowledge_delta | CREATE | ZERO | Y | Y | Y | — | canonical claim identity, provenance and evidence policy |
| Knowledge | update | Y | knowledge_delta | MUTATE_EXISTING | CURRENT_REQUIRED | Y | Y | Y | — | claim identity/provenance and semantic reconciliation |
| Knowledge | retire | Y | knowledge_delta | RETIRE | CURRENT_REQUIRED | Y | Y | Y | — | dependency/reconciliation retirement policy |
| Knowledge | reactivate | Y | knowledge_delta | REACTIVATE | CURRENT_REQUIRED | Y | Y | Y | — | canonical claim and provenance/readiness checks |
| Knowledge | collector_facet_update | Y | knowledge_facet_update | SPECIAL | CURRENT_REQUIRED | N | N | Y | — | Collector Profile/facet contract |
| Knowledge | relation_create, relation_retire, relation_reactivate | Y | capture_child_relation | RELATION_MUTATION | NONE | N | N | Y | — | Graph predicate, endpoints and edge revision |
| Source | create, ingest | Y | source_evidence_reconciliation | CREATE | ZERO | Y | Y | Y | — | source identity, locator, visibility and provenance |
| Source | update | Y | source_evidence_reconciliation | MUTATE_EXISTING | CURRENT_REQUIRED | Y | Y | Y | — | source metadata/provenance policy |
| Source | retire | Y | source_evidence_reconciliation | RETIRE | CURRENT_REQUIRED | Y | Y | Y | — | source retirement and evidence dependency checks |
| Source | reactivate | Y | source_evidence_reconciliation | REACTIVATE | CURRENT_REQUIRED | Y | Y | Y | — | source lifecycle and provenance/readiness checks |
| Evidence | create, ingest | Y | source_evidence_reconciliation | CREATE | ZERO | Y | Y | Y | — | claim/source UUID and revision dependency binding |
| Evidence | update | Y | source_evidence_reconciliation | MUTATE_EXISTING | CURRENT_REQUIRED | Y | Y | Y | — | claim/source dependencies, excerpt and locator |
| Evidence | retire | Y | source_evidence_reconciliation | RETIRE | CURRENT_REQUIRED | Y | Y | Y | — | evidence retirement and dependency policy |
| Evidence | reactivate | Y | source_evidence_reconciliation | REACTIVATE | CURRENT_REQUIRED | Y | Y | Y | — | evidence lifecycle and claim/source binding |
| Video | ingest | Y | governed_video_plan | CREATE | ZERO | Y | Y | Y | — | canonical/external identity, subject and attachment checks |
| Video | update | Y | governed_video_plan | MUTATE_EXISTING | CURRENT_REQUIRED | Y | Y | Y | — | canonical UUID, source identity and completeness |
| Video | source_refresh | Y | video_source_refresh | SPECIAL | CURRENT_REQUIRED | N | N | Y | `nhk_create_proposals` | external source snapshot and source revision |
| Video | retire | Y | governed_video_plan | RETIRE | CURRENT_REQUIRED | N | N | Y | — | no valid Capture owner flow in this scope |
| Video | reactivate | Y | governed_video_plan | REACTIVATE | CURRENT_REQUIRED | N | N | Y | — | no valid Capture owner flow in this scope |
| Video | relation_create, relation_retire, relation_reactivate | Y | capture_child_relation | RELATION_MUTATION | NONE | N | N | Y | — | Graph predicate/endpoints and evidence |
| Media | ingest | Y | media_ingest | CREATE | ZERO | N | N | Y | — | physical asset/storage and semantic ingest boundary |
| Media | update | Y | media_metadata_reconciliation | MUTATE_EXISTING | CURRENT_REQUIRED | Y | Y | Y | — | active Media UUID/revision and metadata contract |
| Media / MediaUsage | add, replace, remove, representative_bind | Y | media_usage_reconciliation | SPECIAL | NONE | Y | Y | Y | `nhk_internal_content_operations` | exact Media/target/usage bindings and usage CAS |
| Media | relation_create, relation_retire, relation_reactivate | Y | capture_child_relation | RELATION_MUTATION | NONE | N | N | Y | — | Graph endpoint/predicate and Media relation policy |
| `wp_post` | relation_create, relation_retire, relation_reactivate | Y | capture_child_relation | RELATION_MUTATION | NONE | N | N | Y | — | native post endpoint and Graph relation policy |
| `wp_post` | subject_bind | Y | wp_post_subject_binding | SPECIAL | NONE | N | N | Y | `nhk_internal_content_operations` | editorial subject-binding and post identity checks |
| Relation/Graph | relation_create, relation_retire, relation_reactivate, relation_replace | Y | capture_child_relation | RELATION_MUTATION | NONE | Y | Y | Y | — | registered predicate, endpoint types, cardinality and edge revision |

## Drift found at baseline

- `ControlledApplyOperationRegistry` used fallback Authority semantics for any
  unknown entity type; unknown pairs were not fail-closed.
- `StagingOperationDescriptor::family()` separately enumerated only selected
  families and hard-coded ingest revision-zero behavior.
- `CaptureDependencyStagingAdmission` separately enumerated dependency owners,
  operations and revision rules.
- Authority, Video, Media and other staging admissions each carried their own
  generic operation lists. These remain owner gates where they also validate
  semantics, but generic support/staging decisions now have an explicit policy
  entry.
- Eligibility used separate owner/operation arrays for staging routing and
  generic lifecycle checks.
- Production capability selection duplicated operation-specific capability
  knowledge instead of consuming a canonical generic capability profile.

These mismatches are policy drift, not authorization to broaden any owner flow.
The explicit denials above preserve current owner behavior where no valid
Capture staging route exists.

## Invariants

Every `Y` Capture-scope entry must be registry-supported, family-defined,
scope-issuable, scope-verifiable, understood by its owner admission and
executor-supported. Every `N` Capture-scope entry must fail closed at scope
issuance or have no staging route. Unknown pairs fail closed in all layers.

## Reuse-only Authority Capture clarification — 2026-10-08

A selected Authority plan containing only `REUSE` candidates performs no
canonical mutation. Therefore:

```text
all selected candidates = REUSE
→ no Proposal
→ no mutation staging scope
→ canonical reuse read-back
→ APPLIED / AUTHORITY_APPLIED
```

This is not a staging bypass. The mutation guard remains fail-closed:

- any selected `CREATE`, `UPDATE`, `RENAME`, `RETIRE`, `REACTIVATE`
  or relation mutation still requires the normal signed exact staging scope;
- mixed plans bind only the mutating candidates into the executable scope;
- unknown candidates, stale fingerprints, invalid revisions/capabilities,
  expired/HMAC-invalid scopes and production-forbidden staging operations
  remain blocked;
- `REUSE` never creates a no-op Proposal or fake update.

Reuse execution is idempotent and must report the canonical UUID/revision from a
freshly resolved/replanned owner. Capture completion may become complete only
when every required/effective canonical child has verified canonical read-back.
Missing read-back remains blocked; public projection is never used as a
substitute for canonical verification.

The plan fingerprint still binds the approved candidate set and owner revision.
If the canonical owner changes between planning and continuation, fresh
replanning changes the fingerprint and requires re-approval before any mutation.

