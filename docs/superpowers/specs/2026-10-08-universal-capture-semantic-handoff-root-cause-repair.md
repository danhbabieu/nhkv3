# NHK V3 Universal Capture / Semantic Handoff Root-Cause Repair

## Status

Approved design. This specification is the implementation boundary for the
universal Capture → semantic owner → Governance → canonical read-back repair.
It does not authorize staging or production mutation.

## Problem statement

The current branch contains a Music/Component universal-scope repair, but the
shared Capture path still permits contradictory and lossy projections. The
reported Capture can have a resolved Music subject and valid Knowledge intent
while exposing an Authority `FAILED_RETRYABLE` track, no Knowledge owner,
stale `SUBJECT_NOT_FOUND`, and a retry decision that disagrees with lifecycle
state. Repeated retries can therefore increase Capture revision without
changing the semantic outcome.

The repair must operate on the shared mechanisms, remain registry-driven for
all canonical Authority types, preserve the existing Capture and Governance
identity, and avoid any schema, migration, parallel pipeline, direct writer,
or data mutation.

## Confirmed evidence

Two isolated repros are present on the clean `main` branch at the starting
commit:

1. `CaptureEnrichmentPlanningEnvelope::fromState()` passes a resolved subject
   result (`status=resolved`, with a canonical primary subject) through the
   generic `CaptureOwnerOutcome::normalizeStatus()`. Because `resolved` is not
   an orchestration status, it falls through to `FAILED_RETRYABLE`.
2. `CaptureCurrentOutcomeReducer::retryEligibility()` returns eligible
   immediately for `CaptureRecord::status=FAILED_RETRYABLE`, even when the
   same Capture has a current hard blocker and `lifecycleState()` returns
   `TERMINALLY_BLOCKED`.

The existing focused Capture/Governance/completion/retry/read-contract slice
passes 180 tests and 819 assertions, with deprecations only. The full unit
suite retains documented baseline failures/errors and a known default-memory
fatal; those are not treated as evidence that this repair is complete.

## Invariants

- WordPress Posts remain editorial truth; Authority, Knowledge, Source/Evidence,
  Graph and Governance retain their existing ownership boundaries.
- The existing Capture UUID, request fingerprint, idempotency key, original
  input fingerprint, revision history and Governance receipts remain stable.
- All semantic types, scopes, facets, provenance classes, predicates,
  dependencies and idempotency material come from the existing runtime
  registries/contracts. No subject-type or fixture-specific branch is added.
- A valid Knowledge candidate is never discarded merely because Source/Evidence
  is absent, pending, or not yet approved. Its state must express the actual
  dependency/review condition.
- Historical failures, approvals, denials, receipts and retries remain
  append-only. Current read models must not treat superseded history as current.
- Retry is bounded by the same current-state decision used for lifecycle and
  read projections. An unchanged dependency fingerprint cannot create an
  unbounded retry loop.
- No direct WordPress writer, direct SQL write, Governance bypass, migration,
  seed, backfill, hard delete, deployment, or live semantic mutation is part
  of this work.

## Design

### 1. Canonical Capture outcome decision

Extend the existing `CaptureCurrentOutcomeReducer` with one internal/public
decision path that evaluates, in order:

1. completion/convergence;
2. active execution lease;
3. current phase failures and completion blockers after supersession;
4. hard/terminal blockers;
5. dependency fingerprint freshness;
6. bounded retry/recovery eligibility.

`lifecycleState()` and `retryEligibility()` must consume the same decision
result. `McpReadHandler::captureGet()` must project both fields from that
result rather than recomputing separate policies. The decision must accept
the same optional continuation input used by execution, while read-only calls
remain deterministic when that input is absent.

The existing public lifecycle vocabulary remains in force:
`COMPLETED_CONVERGED`, `ACTIVELY_EXECUTING`, `RECOVERABLE_INTERRUPTED`, and
`TERMINALLY_BLOCKED`. Existing retry reasons remain compatible unless a more
precise reason is already represented by the current contract.

`FAILED_RETRYABLE` is eligible only when the current decision is not terminal,
not active, and the current failure remains retryable. A hard blocker,
current terminal mismatch, or unchanged decision dependency fingerprint must
override the historical status and deny retry.

### 2. Domain-aware owner-track normalization

Keep `CaptureOwnerOutcome` generic, but make
`CaptureEnrichmentPlanningEnvelope` normalize each owner from its domain
contract before applying the generic fallback:

- resolved Authority subject packets with canonical identity/read-back become
  a successful current Authority track, not `FAILED_RETRYABLE`;
- unresolved or ambiguous subject packets retain their domain failure/review
  state and diagnostics;
- Knowledge, relations, Source/Evidence, media and video continue to use their
  registered result statuses and canonical read-back;
- an owner is `NOT_APPLICABLE` only when the current intent and dependency
  graph prove it is not required. An empty result for a required owner is not
  silently converted into completion.

When a current authoritative result supersedes an old diagnostic such as
`SUBJECT_NOT_FOUND`, the old code is moved to immutable failure history and
removed from current blockers. Reconciliation must be keyed by current phase
receipt/result and canonical identity, not by a fixture-specific subject name.

### 3. Registry-driven semantic plan normalization

Before constructing Governance plans, normalize each candidate through the
existing Authority registry, Knowledge facet profile and semantic guard. The
normalization key is deterministic and includes the Capture identity,
canonical subject identity, normalized candidate content, resolved Knowledge
scope, facet, provenance and evidence/source identity where those fields are
contractually material.

Identical candidates within one continuation are emitted once. Candidates
that differ in subject, scope, facet, provenance, evidence or governed payload
remain distinct. The first valid candidate determines stable plan ordering;
sorting and key generation must remain deterministic across retries.

The planner continues to create dependent relation plans only after a valid
Knowledge plan exists. It must preserve a valid Knowledge plan when a
Source/Evidence dependency is absent or awaiting review, and report the
dependency state explicitly through the existing review/blocker model.

### 4. Current completion owner reconciliation

Update the existing completion reducer so a newer current keyed owner
read-back supersedes an older unkeyed owner track of the same owner type in
the current projection. The old track remains available in append-only
receipts/history. Distinct current owners must not be merged merely because
their owner types match.

Required-owner checks must therefore use the effective current owner set, not
the raw accumulation of historical unkeyed children. A successful Knowledge
read-back must clear the derived empty-owner and
`REQUIRED_OWNER_READBACK_UNVERIFIED` symptoms while retaining any real pending
dependency or Governance blocker.

### 5. Retry continuation and idempotency

Retry continues to rehydrate the latest persisted `continuation_state`, subject
hints and observations. The same Capture UUID, request fingerprint and
idempotency identity are reused. Completed owners and valid canonical
read-backs are reused; only invalidated dependency closures are replanned.

The retry admission decision must be checked again immediately before
execution, and Governance/CAS rules remain authoritative for apply. A retry
with no changed dependency, subject revision, explicit confirmation, or
continuation payload must not produce semantic progress or an artificial
successful revision bump.

## Verification matrix

Add focused tests covering:

- every registered Authority type, including Music, Component, Classification,
  Specimen and Product, through registry-declared Knowledge scopes;
- resolved, ambiguous, unresolved, stale and superseded subject diagnostics;
- valid Knowledge candidates with missing, pending, resolved and rejected
  Source/Evidence dependencies;
- duplicate candidate input and distinct candidate dimensions;
- current versus historical owner tracks, keyed versus unkeyed read-backs,
  required-owner completion and stale symptom clearing;
- `NOT_APPLICABLE`, `REVIEW_REQUIRED`, `FAILED_RETRYABLE`, terminal and
  completed owner outcomes using the existing contract vocabulary;
- lifecycle/retry/read-model agreement for active, recoverable, terminal and
  converged Captures;
- unchanged versus changed dependency fingerprints;
- CAS/revision conflicts, approval/authorization boundaries, signed packet
  boundaries, Governance idempotency, canonical read-back and public
  projection readiness;
- the supplied Capture identity and its repeated-retry regression shape using
  synthetic/persisted fixtures only; no external runtime mutation.

Required verification includes the focused regression slice, relevant full
unit selections, PHP lint, `git diff --check`, secret review, and the
documented full-suite baseline comparison. Runtime acceptance remains blocked
unless the exact authorized TEST runtime identity is independently verified.

## Non-goals

- No new semantic owner, entity type, relation, facet, schema field, migration
  or parallel Capture pipeline.
- No import or parsing of legacy article bodies.
- No staging or production semantic mutation.
- No public UI redesign beyond correcting the existing truthful projection of
  current owner/read-back state.

## Implementation sequence

1. Add regression tests that fail on the two confirmed repros and the stale
   owner/candidate cases.
2. Implement the shared outcome decision and domain-aware owner normalization.
3. Implement planner deduplication and completion effective-owner
   reconciliation.
4. Integrate retry/read projections and verify no regression in existing
   Article, Video, Media and Dictionary continuation paths.
5. Update `V3_EXECUTION_STATE.md` with evidence, baseline comparison and
   runtime boundary; do not claim live acceptance or deployment.
