# Capture/Governance convergence repair plan

## Scope

Repair the shared Proposal subject binding and make existing-Capture Video
continuation converge in bounded, durable steps. Live/staging data and
deployment remain out of scope for this implementation pass.

## Evidence already established

1. `VideoIntakeService::proposalArguments()` and
   `McpGovernanceHandler::createFromArguments()` provide the canonical Video
   UUID as `subject_id`.
2. `GovernanceMigration003` has no `subject_id` column. `WpdbProposalRepository::create()`
   stores only `entity_type`, `target_uuid`, and the JSON command; `hydrate()`
   reconstructs non-relation `subject_id` from `entity_type`.
3. The idempotency comparison and binding fingerprint also use `entity_type`
   when it is present, so a legacy malformed row can be replayed as if it were
   the requested command.
4. `EditorialCaptureCoordinator::continueWithAddendum()` always reaches the
   semantic write-back closure. That closure rebuilds the Video provenance
   plan from persisted assets, including the original child idempotency key.
   `GovernedCaptureContinuationService` then re-enters Source/Claim/Evidence/
   Video orchestration even for a text-only addendum.
5. `ControlledApplyService` owns a transaction around the full executor call;
   this is the lock/external-call boundary that must be audited and covered by
   regression tests, without broad timeout changes.

## Implementation sequence

1. Add failing unit tests for Proposal repository round-trip, Video and
   component UUID binding, fail-fast invalid UUID-bound inputs, and legacy
   malformed idempotency rows. Keep existing logical relation/create fixtures
   compatible where their contract does not require a UUID subject.
2. Add an explicit Proposal binding validator at the Governance create
   boundary. UUID-bound Video ingest/update and component merge/rekey/update
   commands must have a valid subject UUID; Video `payload.canonical_id` must
   match it. Invalid bindings fail before persistence and are not converted to
   retryable failures.
3. Add a forward-only Governance schema migration that stores `subject_id`
   independently. Backfill only deterministically recoverable values from
   `target_uuid`, relation `source_uuid`, or creation `payload.canonical_id`;
   leave unresolved legacy values visibly invalid for repair rather than
   silently declaring them canonical. Wire the migration into the guarded
   migration runner and target version.
4. Change `WpdbProposalRepository` persistence/hydration/idempotency comparison
   to use the stored subject UUID/value exactly. Preserve relation source
   binding and make malformed legacy rows readable as invalid candidates for a
   governed repair path, not eligible commands.
5. Reuse and harden `VideoProposalReconciliationService` as the explicit
   replacement/supersede repair boundary. A legacy approved-but-not-applied
   malformed Video proposal is never edited in place; repair creates a fresh
   correctly-bound Proposal and only supersedes after controlled apply/readback.
   The repair is idempotent by replacement key and refuses applied/uncertain
   legacy state.
6. Inject that repair boundary into Capture continuation. Before applying a
   Video child returned by idempotency hydration, detect the invalid binding
   and return/execute the explicit repair path. A deterministic binding error
   is `SYSTEM_BLOCKED`/repair-required, never `FAILED_RETRYABLE`.
7. Add persisted child/dependency fingerprints to Capture diagnostics/receipts
   and make a text-only addendum skip an unchanged failed Video child. Resume
   only when relevant source/subject/dependency state changes or an explicit
   governance control requests repair. Keep one Capture/Post and existing
   dependency identities.
8. Make continuation phase execution bounded by existing durable receipts:
   avoid replaying completed Source/Claim/Evidence work and return a structured
   state when the child is unchanged or blocked. Do not add a second semantic
   store and do not alter global HTTP/PHP timeouts.
9. Add tests for timeout-shaped bounded return, text-only addendum/replay,
   dependency resume, conflict, corrupt/invalid binding, transaction cleanup,
   and legacy Capture-shaped state. Run focused tests first, then full Unit,
   lint, diff check, and secret review. Integration/live acceptance remains
   blocked by the documented checkpoint mismatch and unavailable database.

## Verification gates

- RED tests must demonstrate the current repository loses the UUID subject.
- GREEN tests must prove Proposal review/read-back, eligibility, and controlled
  apply all see the same canonical subject identity.
- No live/staging mutation or deployment.
- Re-read `V3_EXECUTION_STATE.md` before each checkpoint and append only
  evidence-backed checkpoint notes.
