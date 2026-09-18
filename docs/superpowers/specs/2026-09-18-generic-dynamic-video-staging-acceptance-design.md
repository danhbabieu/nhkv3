# Generic Dynamic Video Staging Acceptance

## Status and scope

This design defines the local implementation boundary for server-issued,
exact staging acceptance of Capture-owned Video plans. It covers both new Video
ingest and existing Video update/correction. It does not authorize production,
legacy data migration, direct database writes, direct WordPress writers, or
semantic writes owned by Knowledge, Source/Evidence, Graph, or Authority.

The change is code-policy only. No database migration, Proposal retrofit, data
backfill, deployment, push, or remote source edit is part of this design.

## Goals and invariants

The canonical flow remains:

```text
nhk.capture.ingest
  -> Capture-owned Video identity and plan
  -> server admission and immutable signed scope
  -> Proposal payload.staging_acceptance
  -> Submit / Approval policy / Eligibility
  -> OperationScopedStagingGuard
  -> ControlledApplyService
  -> canonical Video read-back
```

The scope is generic in code, but never broad in authority. Each issued packet
authorizes one exact execution. Production remains fail-closed. A direct
`nhk.video.ingest` request does not receive Capture-owned automatic admission,
and an internal Proposal creator cannot manufacture a valid scope.

The existing `StagingAcceptanceScopeVerifier`, HMAC secret,
`OperationScopedStagingGuard`, Proposal repository, Eligibility path, and
`ControlledApplyService` remain the owners of their current responsibilities.
No second signer, secret, Proposal store, or Governance path is introduced.

## Exact server-owned binding

`StagingAcceptanceScopeVerifier::issueForVideoPlan()` will construct the
unsigned binding from the persisted Capture and the already-resolved,
Capture-owned plan. Client-provided `staging_acceptance` is never an input to
authorization; it is ignored or rejected when present in the incoming command.

The binding includes, at minimum:

- `environment: staging` and `semantic_write_policy: PROJECT_BUILD`;
- `canonical_entrypoint: nhk.capture.ingest`;
- `operation_family: governed_video_plan` and registered operation `ingest` or
  `update`;
- Capture UUID and persisted Capture request fingerprint;
- exact plan fingerprint and proposal command fingerprint;
- Video platform, external Video ID, and canonical source URL;
- proposed/canonical Video UUID;
- exact resolved subject type, UUID, and subject revision when applicable;
- update target UUID and current expected revision, or explicit create/ingest
  semantics for a new Video;
- expiry, scope fingerprint, and server HMAC signature.

The canonical binding is used consistently for admission, signing, Proposal
attachment, Proposal verification, and apply-time checks. Fingerprints are
computed after removing authorization fields and use the repository's existing
`CommandCanonicalizer`.

For `ingest`, admission requires a read-only duplicate audit proving that no
canonical Video exists for the same external identity. The proposed UUID is
bound to that one plan. If the external identity already exists, the ingest
scope is refused; reuse/update must be planned through the appropriate
Capture-owned path.

For `update`, admission requires the exact canonical target UUID and current
revision. If the revision advances before apply, CAS fails closed with the
existing revision-conflict behavior. Apply never refreshes the expected
revision implicitly; a fresh canonical read and new governed plan/scope are
required.

## Admission policy

The existing `VideoStagingAdmission` filter provider remains the generic policy
owner. It admits only when every condition below holds:

1. Runtime environment is staging and production is rejected.
2. The canonical semantic write policy is `PROJECT_BUILD`.
3. The request is reached through the Capture-owned entrypoint.
4. The persisted Capture exists and its request fingerprint matches exactly.
5. Capture intent is `VIDEO`.
6. Video source identity is valid and matches the plan.
7. The subject resolution packet is canonical, typed, exact, and non-conflicting.
8. The operation is registered as `ingest` or `update`.
9. An update has the current exact expected revision.
10. An ingest has no canonical duplicate for the external identity.
11. The duplicate audit and all required readiness checks pass.
12. Target, subject, plan, and proposal fingerprints match the server-owned
    plan.
13. The scope has not expired and its HMAC is valid at verification time.

The policy must not contain Capture IDs, Video IDs, subject IDs, request names,
YouTube IDs, W64 exceptions, or any growing environment allowlist. Existing
W64 behavior must pass through this policy without a W64-specific provider.

The policy is limited to the Video plan. It cannot authorize Knowledge,
Source/Evidence, Graph, Authority, Article, or unrelated Media mutations.

## Component changes and boundaries

### Capture and plan construction

`GovernedCaptureContinuationService` remains responsible for taking the
server-owned Video plan and attaching the freshly issued scope. Its scope
helper must cover `ingest` and `update`, preserve the server-derived Capture
fingerprint, and bind the Proposal command fingerprint. The helper must not
accept a scope supplied by connector input.

`Plugin.php` and `GovernanceRuntimeFactory` continue to compose the existing
provider, verifier, and issuer. The issuer resolves the persisted Capture by
Capture ID before asking the verifier to sign; an absent or mismatched Capture
fails closed.

### Scope verification and Proposal boundary

`verifyProposal()` first verifies environment, expiry, fingerprint, and HMAC,
then validates the exact Video binding against the Proposal's target,
operation, subject, external identity, plan fingerprint, and command
fingerprint. `OperationScopedStagingGuard` remains the apply boundary and
requires a verified `staging_acceptance` packet for staging governed writes.

The Proposal lifecycle and `GovernanceAutomationPolicyResolver` remain
separate. Approval automation cannot bypass scope issuance or scope
verification.

### Static admission removal

The implementation will first establish generic parity with focused tests. Only
after W64 regression passes through the generic provider may the old
`VideoW64StagingAdmission` implementation be removed or retained solely as
historical/test evidence. No future ordinary Video may require a new admission
class or static entry.

## Failure behavior

All missing, stale, conflicting, client-forged, duplicate, expired, production,
wrong-entrypoint, wrong-operation, wrong-subject, wrong-target, changed-plan,
changed-command, invalid-HMAC, and changed-revision cases fail closed. Old
Proposals without `staging_acceptance` remain invalid; they are not retrofitted
or mutated. Retries create a fresh governed command and scope according to the
existing retry contract.

The 400-day Video case is a regression fixture for the generic path only. Its
identifiers are not authorization, configuration, or runtime policy.

## Verification contract

The implementation plan will add RED tests before implementation for:

- valid new ingest and valid existing update;
- external identity, Capture, request fingerprint, subject, plan, and command
  tampering;
- subject conflict and duplicate external Video during ingest;
- update CAS failure after revision advance;
- operation mismatch in both directions;
- expiry, HMAC/signature, production, direct writer, and client-supplied scope;
- Proposal creation without a Capture-owned scope;
- W64 generic-path regression and the 400-day ingest regression;
- two unrelated Captures receiving distinct non-reusable scopes.

After implementation, run the focused Video scope, verifier, Capture
continuation, Video ingest/update, Proposal eligibility, Controlled Apply,
retry, and W64 suites, followed by changed-file PHP lint, Composer lint,
`git diff --check`, and secret scanning. Broader failures must remain
distinguishable from this slice and must not be hidden.

## Explicit non-goals

- No production or staging data mutation.
- No deployment, push, SSH, SCP, rsync, or server-side source edit.
- No direct SQL or generic WordPress writer.
- No database migration unless implementation proves an existing contract
  cannot represent the binding; that would require a new design review.
- No Knowledge creation from Video descriptions without its own governed plan.
- No automatic approval or publication policy change.
