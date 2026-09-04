# Governed Semantic Ingest Lifecycle Design

## Goal

Make Source, Knowledge Claim, Evidence, Video and Video `about` relation ingest
distinguish Governance proposal identity from canonical entity identity, enforce
dependency order, and require canonical owner read-back before downstream
progress.

## Invariants

- `proposal_id` is always a Governance proposal UUID; `target_uuid` and
  `canonical_id` are canonical entity UUID fields and never contain proposal IDs.
- Create/ingest proposals return `canonical_id: null` until Controlled Apply
  produces and verifies a canonical record.
- Evidence dependencies require canonical, active Claim and Source records and
  canonical, active Evidence records. Visibility is independent of lifecycle;
  PRIVATE/HIDDEN records are verified through an owner/internal boundary and are
  never made PUBLIC for verification.
- Orchestration only calls Governance create, submit, review/approve according
  to policy, eligibility and apply. It never writes semantic repositories or
  auto-approves a proposal when manual approval is required.
- Controlled Apply success requires owner/internal read-back matching entity
  type, canonical UUID, active state and revision/snapshot. A result UUID alone
  is not success.
- Video `EXPLICIT_USER_RELATION` `about` candidates retain non-empty
  `evidence_refs` pointing to canonical active Evidence.
- Retries reuse idempotency key plus content and dependency fingerprints and do
  not create duplicate canonical records.

## Data flow

`Source proposal → verified Source → Claim proposal → verified Claim → Evidence
proposal → verified Evidence → Video proposal → verified Video → governed
relation proposal → verified relation`.

Every proposal remains in the normal Governance lifecycle. Downstream proposal
creation or apply is blocked when an upstream proposal is not active and
verified.

## Failure semantics

Invalid identity/dependency input returns a structured fail-closed error with a
stable code, field and received identifier. Public readers continue to hide
PRIVATE/HIDDEN Evidence. Internal owner read-back is verification-only and does
not alter visibility.
