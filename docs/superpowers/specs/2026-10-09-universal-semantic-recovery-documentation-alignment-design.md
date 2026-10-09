# NHK V3 Universal Semantic Recovery & Documentation Alignment

## Status and authority

This is the approved design boundary for the next implementation slice. It
operates under the NHK V3 Constitution, the active Capture/Knowledge/
Source-Evidence/Governance contracts, and the runtime registries. It authorizes
code, tests and documentation changes on the local branch only. It does not
authorize staging or production mutation, deployment, migration, seed,
backfill, push or cutover.

## Problem and evidence boundary

The shared Capture recovery path must remain correct when semantic admission
policy changes between the original Capture and a retry. The current design
contains two risks that must be verified with a failing regression test before
implementation is declared:

1. `SemanticClaimCandidateGuard` owns semantic admission behavior but exposes no
   server-owned admission-policy version. The decision fingerprint therefore
   cannot reliably distinguish an old Capture evaluated under an older
   admission policy from the same dependencies evaluated under the current
   policy.
2. `EditorialCaptureCoordinator::save()` persists a decision fingerprint for
   `REVIEW_REQUIRED` but not for every retryable failed continuation. A
   `FAILED_RETRYABLE` Capture can consequently lack the persisted decision
   needed for bounded no-progress detection.

The implementation must first add the regression probe and observe it fail on
the current branch. Only then may the runtime change be made. If the probe
does not fail, the suspected root cause is not confirmed and the design must
be revised before implementation continues.

## Goals

- Make the semantic admission policy a server-owned, versioned dependency of
  Capture decision fingerprints.
- Re-evaluate an old Capture at most once when that policy version changes,
  while preserving the existing no-progress lock and current lifecycle/read
  model.
- Keep review, reject, hard-block, authorization/capability denial,
  Governance denial, identity conflict, idempotency conflict and CAS/revision
  conflict fail-closed.
- Prove the real chain
  `TextInputInterpreter → SemanticClaimCandidateGuard → GovernedCaptureContinuationService → canonical Knowledge read-back`
  without bypassing Governance or inventing fixture-specific vocabulary.
- Align active documentation with executable registries, validation,
  execution, persistence and public projection for every registered Authority
  type.

## Non-goals and fixed boundaries

- No new entity type, Knowledge scope, facet, predicate, relation, schema
  field, migration, writer or parallel Capture pipeline.
- No generic WordPress writer, direct SQL/database write, Governance bypass,
  legacy article-body import, staging acceptance or production mutation.
- No automatic promotion of transcript, OCR, generated prose, image hints or
  other derived material to Knowledge or Evidence.
- No hard-coded Westminster subject, name, UUID, slug, route or other fixture
  branch. Westminster remains a documentation/test example only.
- No replay of a completed owner mutation. Canonical owner IDs, revisions,
  receipts, Capture UUID, request fingerprint and idempotency identity remain
  stable.

## Design

### 1. Server-owned admission policy fingerprinting

Add a source-controlled policy version at the semantic admission boundary,
preferably as a public constant on `SemanticClaimCandidateGuard` so the
runtime owner and the fingerprint cannot drift. The version is incremented
only when admission semantics change; it is never accepted from Capture input,
client metadata or fixtures.

`CaptureDecisionDependencyFingerprint` must include that value in its
canonical hashed payload and bump its own payload format version. The existing
video policy dependency remains separate. A legacy persisted fingerprint that
does not contain the current semantic admission version is treated as stale,
not as equivalent to the current policy.

### 2. One bounded retry decision

Persist the current decision fingerprint after every completed retryable
continuation state that can be re-evaluated, including `REVIEW_REQUIRED` and
`FAILED_RETRYABLE`, without replacing append-only phase history. The current
fingerprint is the latest decision input; prior fingerprints remain historical
evidence.

`CaptureCurrentOutcomeReducer` remains the single decision authority for
`lifecycleState()`, `retryEligibility()`, GET/read projection and continuation
admission. A policy-version change may open one bounded re-evaluation when the
current state is otherwise recoverable. After the retry persists an unchanged
current fingerprint, a second retry is denied as no-progress. A changed
fingerprint may reopen only the registered recoverable dependency closure.

The reducer must classify immutable gates explicitly. The following remain
closed even if the policy version changes:

- hard/terminal blockers;
- authorization or capability denial;
- Governance denial or contract/binding/invariant failure;
- identity ambiguity/conflict;
- idempotency conflict or stale binding;
- optimistic revision/CAS conflict;
- a completed or already-applied owner mutation.

`GOVERNANCE_APPROVAL_REQUIRED` is a review/owner-action state, not an
automatic Governance denial, and must retain its existing review semantics.
Review and rejection outcomes remain visible and are never rewritten as
success merely because a newer policy exists.

### 3. Governed Knowledge chain and read-back

The continuation service must use the existing registry-driven plan and
Governance lifecycle. The proof path is:

1. interpret an explicit user Knowledge statement;
2. reject derived-only material and unsupported scope/facet combinations;
3. admit the valid candidate through `SemanticClaimCandidateGuard`;
4. build the registered Knowledge proposal with the existing Capture and
   idempotency identity;
5. execute through `GovernedCaptureContinuationService` and Governance;
6. read the canonical Knowledge owner back by stable identity and revision;
7. persist the owner result, receipt and current decision fingerprint;
8. project public dossier eligibility only when the canonical readiness and
   provenance contract permits it.

Missing, pending or rejected Source/Evidence must remain an explicit
dependency/review state. It must not discard a valid Knowledge candidate or
silently make a required owner complete.

### 4. Registry-wide parity

The verification matrix must cover all nine registered Authority types:
`brand`, `model`, `variant`, `movement`, `music`, `component`,
`classification`, `specimen` and `product`. For each type, evidence must map:

`Document Contract → Runtime Registry → Input Validation → Execution →
Persistence → Public Projection`.

The matrix must identify unsupported, not-applicable, review-required and
publicly-ineligible states without creating new runtime vocabulary. Article
remains editorial Posts truth; a Knowledge delta does not create an Article.

### 5. Documentation alignment

Update only the active owner documents, keeping one canonical rule in the
lowest-level contract and linking upward:

- MCP Content Operations and Control Plane: Capture entry, continuation,
  retry/recovery, completion and fail-closed boundaries.
- Universal Structured Semantic Intake: lexical versus semantic tracks,
  explicit-user admission, derived-material rejection and policy-versioned
  recovery.
- Knowledge Source Model and Governed Living Knowledge: claim provenance,
  scope/facet validation, Source/Evidence dependency and canonical read-back.
- Article Ingest Contract: intent routing, editorial Post ownership and the
  no-Article boundary for Knowledge deltas.
- Music Data Collection Standard: read-only coverage/rights/notation/audio
  distinctions; the Westminster worked example stays noncanonical.
- Public Entity Dossier Projection Contract: readiness, direct Knowledge
  identity and public eligibility after canonical read-back.
- Current Documentation Status Index: status and links only, not a duplicate
  normative rule.

Documentation must not claim live acceptance, deployed parity or external
runtime success when the authorized TEST runtime identity is unavailable.

## Required regression and contract coverage

The implementation plan must include tests that initially fail for the
confirmed defect and then pass after the fix:

- the semantic admission policy version changes a legacy/current dependency
  fingerprint;
- an explicit user candidate is admitted while derived/dictionary-only input
  remains blocked or review-required;
- an old Capture with the same UUID, request fingerprint and idempotency key
  gets one policy-version retry;
- an unchanged retry is blocked and cannot increase semantic progress;
- hard, terminal, authorization, capability, Governance-denied, identity,
  idempotency and CAS failures remain blocked across policy changes;
- review/reject states and append-only phase receipts remain intact;
- completed canonical owner read-back is reused and never reapplied;
- all nine registry Authority types use their declared Knowledge scope;
- the real interpreter → guard → governed proposal → canonical read-back
  chain preserves idempotency and optimistic revision behavior;
- public dossier projection is eligible only after the canonical readiness
  contract, and Knowledge delta does not create Article content;
- shared code contains no Westminster-specific branch or live mutation path;
- documentation/runtime parity checks cover the contract-to-projection chain.

Verification must include the focused regression selection, relevant full unit
selections, PHP lint, `git diff --check`, a scoped secret review and the
repository's documented full-suite baseline comparison.

## Acceptance/report mapping

The implementation report must state, with evidence:

1. root cause confirmed by the failing probe;
2. changed code and bounded retry behavior;
3. active documentation changes and runtime parity matrix;
4. registered entity-type coverage;
5. Governance, idempotency, revision and canonical read-back behavior;
6. public projection/readiness boundaries;
7. focused and full verification results, including baseline failures;
8. commit identity and any blockers;
9. deployment/readiness boundary, explicitly stating that no external publish
   or semantic mutation occurred.
