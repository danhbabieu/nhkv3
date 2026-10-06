# Capture Retry Lifecycle: Superseded Failure Design

## Status and scope

This design repairs the general NHK V3 Capture retry lifecycle so that an
earlier retryable failure remains auditable but cannot remain an active blocker
after a later retry has produced the effective outcome for that phase.

The change is limited to Capture phase receipts, current-outcome reduction,
diagnostic/completion projection, Article pre-create review input and MCP retry
read-back. It does not alter the UTF-8 producer fix, create a new Capture,
delete history, bypass Article preflight or Governance, mutate staging or
production, or special-case any failure code or subject.

## Confirmed root cause

The current flow has four interacting defects:

1. `EditorialCaptureCoordinator::run()` initializes its working diagnostics
   from the persisted Capture. A retry therefore carries the previous
   top-level `diagnostics.failure` forward.
2. `EditorialCaptureCoordinator::save()` derives a new non-complete phase
   receipt's `failure_code` from that top-level failure before appending the
   new attempt. A later `ARTICLE_PRE_CREATE_REVIEW` attempt can consequently
   receive the old `CAPTURE_UTF8_INVALID` code even when interpretation and all
   required preceding phases have completed.
3. `settleHistoricalFailure()` only runs after final read-back. Intermediate
   review/blocker decisions do not move the old failure into audit history or
   remove it from current diagnostics.
4. `CaptureCurrentOutcomeReducer::failureCode()` reads latest phase failures
   and then falls back to persisted completion blockers, but does not apply the
   receipt reducer's supersession information. MCP therefore reports a stale
   historical failure as current and can reject a valid retry.

Article pre-create research itself remains a current owner/preflight boundary;
it must continue consuming fresh research and canonical subject/media state.
The fix is in the shared Capture current-outcome lifecycle, not in Odo,
UTF-8, Article quality or a failure-code exception.

## Effective outcome model

`CapturePhaseReceiptReducer` remains the single receipt reducer. Each phase
retains an immutable append-only `attempts` list and exposes exactly one
effective `latest` attempt. The latest attempt is the only phase outcome used
for current status, blocker and retry decisions.

For each phase:

- a completed latest attempt supersedes retryable failures from earlier
  attempts of that phase;
- a latest retryable failure remains current, even when its code matches an
  older failure;
- a latest failure with a different code replaces the old code for current
  decisions while the old code remains historical;
- review and terminal/system-blocked outcomes remain current requirements and
  cannot be hidden by a successful unrelated phase;
- historical failure codes are exposed only as audit/superseded metadata.

The reducer will expose shared helpers for effective latest outcomes, current
failure codes and superseded failure codes. No second retry-state mechanism
will be introduced.

## Diagnostic and blocker reconciliation

After the current phase attempt is appended, the coordinator will reconcile
derived Capture diagnostics from effective receipt state:

- move a stale top-level failure into append-only `failure_history` with the
  supersession evidence and remove it from the active failure projection;
- retain a newly produced current failure, owner-review requirement or system
  block;
- remove superseded failure codes from derived completion/blocker lists while
  retaining real current blockers;
- preserve all receipt attempts, audit diagnostics, governance records,
  revisions and idempotency metadata.

This reconciliation is lifecycle-driven. It does not inspect or branch on
`CAPTURE_UTF8_INVALID`, Odo 24, or any other particular code.

`ARTICLE_PRE_CREATE_REVIEW` will therefore receive only the current effective
Capture state. Fresh Article research/preflight blockers and owner-review
requirements remain blocking; a superseded historical failure cannot add an
independent blocker.

## Retry admission

`CaptureCurrentOutcomeReducer::retryEligibility()` and the MCP Capture read
projection will consume the same effective current outcome helpers. Retry
continues to require the existing Capture ID, exact original idempotency key,
request fingerprint, documentation checkpoint, revision/CAS and Governance
controls. A non-retryable current state remains non-retryable.

The executor remains the only authority that performs continuation. The read
projection reports the same decision but never grants mutation permission.

## Regression matrix

Focused tests will cover the lifecycle independently of UTF-8:

- retryable failure X followed by success: X is historical only;
- retryable failure X followed by X: X remains current;
- retryable failure X followed by Y: Y is current and X is historical;
- later successful phases do not resurrect X;
- current owner review remains blocking without historical X replacing it;
- current system-blocked/non-retryable failure remains authoritative;
- same retry idempotency replay reuses the Capture and canonical outputs;
- changed request fingerprint is rejected by the existing binding contract.

Existing UTF-8 regression tests, including the malformed candidate-field
coverage and the c908cbfb producer fix, remain unchanged.

## Verification and safety

The implementation will use test-first cycles, then run the focused Capture /
Article / MCP tests, PHP lint for changed PHP files, the configured broader
test suite as feasible, `git diff --check`, and a secret review. The execution
state ledger will receive a dated local/no-data-mutation checkpoint after
verification. No database migration, staging acceptance, deployment, push or
production operation is part of this design.
