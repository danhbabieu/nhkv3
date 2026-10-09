# Specimen Catalogue Relationship Extension — Implementation Plan

Design baseline: adae28f08267ed479e8d2d6a4f966e7a94ca5bbb
Status: plan only; implementation is blocked at design approval
Companion: docs/superpowers/specs/2026-10-09-specimen-catalogue-relationship-extension-design.md

## 1. Registry and schema impact

### 1.1 Candidate registry changes

After explicit approval, extend executable relation vocabulary with two typed
predicates:

| Candidate | Source | Target | Outbound | Inbound | Required policy |
|---|---|---|---:|---:|---|
| specimen_of | Specimen | Model or Variant | 0..1 active | 0..N | one identity target; no Model+Variant pair; parent-chain validation |
| lists_specimen | Product | Specimen | 0..1 active | 0..N | specific Product exact-one semantics; historical relation; no bundle |

The registry extension must update as one versioned contract change:

- predicate endpoint allowlists and registry hash/version;
- RelationPolicy cardinality, self-edge and conflict rules;
- Governance operation family, high-impact classification and Controlled Apply;
- relation-context scope codes and provenance/Evidence requirements;
- public traversal/read-model policy and direct/derived labels;
- MCP/admin descriptors, documentation snapshot and parity tests;
- diagnostics for compatibility mismatch, identity conflict, product missing or
  conflicting specimen and stale revision.

No new Authority type, owner or Product/Specimen canonical field is required.
Existing GraphRelationContext is the recommended persistence boundary; do not
create a parallel relation table unless an approved performance study proves
current Graph indexes insufficient.

### 1.2 Migration decision

Design phase: no migration, no registry mutation and no runtime write.

Implementation phase: a forward-only additive migration is expected to seed the
two predicate dictionary entries in the deployed Graph registry. This is
registry-data migration, not a new semantic-owner schema. Existing Graph edge,
context, source/target and uniqueness indexes should be reused.

An additional index/read model is not approved yet. It requires a read-only
benchmark showing current target/predicate indexes cannot page 10,000+ Specimens
or 100+ historical Products per Specimen within the accepted budget. If needed,
it is a separate additive migration, never a semantic shortcut.

### 1.3 Compatibility field plan

Specimen.model_uuid stays during the first implementation slice. It is read for
diagnostics/cross-check only and never canonical. Deprecation or backfill needs
its own inventory, reconciliation report, approval and rollback strategy. This
plan does not change or drop it.

## 2. Rollback and failure containment

Rollback is contract-gated, not destructive:

1. Stop new apply/read paths with the feature/contract gate.
2. Retain predicate dictionary rows, edges and relation contexts for audit.
3. Do not run DOWN, DROP, TRUNCATE, reset or delete semantic history.
4. Correct an invalid relation only through governed retire/replacement.
5. Preserve Capture, proposal, Evidence, idempotency and read-back receipts.
6. If old code cannot safely read new predicate rows, deploy a compatible read
   gate before registry activation.

No rollback may delete the Specimen, Product, Video or Evidence involved.

## 3. Implementation slices — only after approval

### Slice 0 — Contract and registry admission

- Add contract definitions and registry entries for both predicates.
- Add endpoint/cardinality/revision/provenance policy.
- Add explicit not-about/not-payload rejection tests.
- Add migration that seeds only registry dictionary entries.
- Verify registry hash parity and no unknown vocabulary.

### Slice 1 — Specimen identity relation

- Add controlled operation for Model/Variant target.
- Enforce one active target and no Model+Variant coexistence.
- Validate Variant → Model → Brand without shortcuts.
- Add model_uuid compatibility mismatch diagnostics.
- Add governed replace/retire history and CAS/idempotency behavior.

### Slice 2 — Product listing relation

- Add Product → Specimen controlled operation.
- Enforce 0..1 outbound and reject multi-object relation.
- Integrate specific/generic Product assessment with canonical relation read-back.
- Implement relist/sold/archive/history and governed reassignment.
- Keep listing copy and commercial payload separate from physical truth.

### Slice 3 — Capture/Video continuation

- Bind resolved-subject packet to exact Capture/request and source identity.
- Route concrete ÔĐô 36/8 video to Specimen, not Variant.
- Preserve SUBJECT_CONFLICT_REVIEW_REQUIRED and
  CAPTURE_SUBJECT_RECONCILIATION_NOT_AMBIGUOUS guards.
- Reuse existing Video UUID/idempotency on approved correction; no duplicate.

### Slice 4 — Read model and scale

- Implement paginated Specimen/Product catalogue queries using Graph target
  indexes and keyset cursors.
- Batch Model/Variant/Brand/Clock Type facets without hydrating all rows.
- Paginate Videos, MediaUsage, Products and Knowledge independently.
- Benchmark 10,000 Specimens on one Variant and 100 historical Products on one
  Specimen before proposing an additive index/read model.

### Slice 5 — Public projection and SEO

- Add verified relation facets to /hien-vat/ and /san-pham/.
- Add exact-object and generic-listing states to detail pages.
- Keep Public Identity, canonical routes, no UUID/stable key and direct/derived
  labels.
- Add unavailable/conflict/empty handling and real query-backed SEO metadata.

### Slice 6 — Acceptance packet and operational handoff

- Run local contract, migration, Graph, Governance, Capture, Video, public and
  scale tests.
- Prepare signed server-issued packet only if separately approved staging
  acceptance requests it; exact IDs and runtime identity must be supplied.
- Perform no staging/production mutation in this design checkpoint.

## 4. Acceptance criteria

### Identity relation

- Registry accepts only registered Specimen → Model/Variant endpoints.
- One active specimen_of target is enforced; two active targets fail closed.
- Variant target derives Model/Brand only through existing parent edges.
- No direct Specimen → Brand shortcut is created.
- Parent, source and target revisions plus Evidence are required.
- Same packet replay is idempotent; changed packet/revision is rejected.
- Reclassification preserves retired history and requires high-impact Governance.
- model_uuid mismatch is visible as compatibility conflict, never silently
  overwritten.

### Product relation

- Product has zero or one Specimen; Specimen has many Products over time.
- Specific Product without exact Specimen remains incomplete or blocked.
- Generic Product remains explicitly generic.
- Multi-object and ambiguous candidate links fail closed.
- Sold/expired/archived Product does not delete Specimen or relation history.
- Relist can reuse the physical Specimen without duplicating it.
- Reassignment is governed and auditable.

### Scope and public behavior

- Shared Model/Variant Knowledge is not copied to every Specimen.
- Multiple Videos can target one Specimen with exact Evidence and no Variant
  representative mislabel.
- /hien-vat/ and /san-pham/ expose public identity only, never UUID/stable key,
  revision or raw internal packet.
- Brand/Model/Variant/Clock Type filters use canonical relations/read services.
- Empty, unavailable, conflict and registry-gap states remain distinct.

## 5. Regression test matrix

| Area | Required cases |
|---|---|
| Registry | known predicates remain valid; unknown predicate/endpoint rejected; direction and cardinality exact |
| Identity | Model target; Variant target; Model+Variant conflict; two Variant conflict; inactive parent; direct Brand rejection |
| Compatibility | matching model_uuid; mismatch diagnostic; missing field does not fabricate identity; no backfill |
| Governance | proposal fingerprint; approval; exact revisions; dependency closure; expiry; Evidence; idempotent replay; governed retire/reactivate/replace |
| Product | generic 0 edge; specific 1 edge; 2 edges rejected; 0/2 candidate conflict; relist; sold/archive; reassignment; merge does not merge Specimen |
| Dedupe | same Product/Specimen replay; same URL different object; reused image; same title; wrong serial; concurrent apply |
| Capture/Video | conflict remains review-required; resolved packet accepted; generic candidate on resolved packet returns CAPTURE_SUBJECT_RECONCILIATION_NOT_AMBIGUOUS; same idempotency no duplicate; target is Specimen |
| Knowledge/Media | direct Specimen observation; shared Variant claim read without copy; MediaUsage reuse; multiple Videos; no automatic promotion |
| Scale | 10,000 Specimens/one Variant cursor pages; 100 Products/one Specimen history; stable ordering/no duplicate/no skipped row; bounded query count |
| Public | archive/detail routes; filters; direct/derived label; no UUID in HTML/JSON-LD/URL; real video/listing history; empty versus unavailable versus conflict |
| Safety | no Music/Côn hoa thị changes; no article import; no staging/production mutation; additive migration and non-destructive rollback |

## 6. Separate approval gates

The following need explicit user/architecture approval before any implementation
slice begins:

1. Exact predicate names specimen_of and lists_specimen.
2. Identity rule: one active Model-or-Variant target and no direct Brand edge.
3. Use of existing GraphRelationContext rather than a parallel relation store.
4. Treat model_uuid as compatibility-only in the first cut.
5. Product relist/history and reassignment semantics.
6. Exact scope/provenance/Evidence requirements for identity and listing links.
7. Public filter and historical Product visibility rules.
8. Scale budget and whether additive index/read model is justified.
9. Acceptance-case target packet for the supplied Capture, including exact
   Specimen UUID and authorized actor, if runtime acceptance is later requested.
10. Registry migration and non-destructive rollback strategy.

Until these gates are approved, the correct status is design-ready but
implementation-blocked. No predicate is ACTIVE merely because this plan names it.
