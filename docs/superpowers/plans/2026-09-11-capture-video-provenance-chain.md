# Capture Video provenance dependency chain

## Objective

Make a new Video submitted through `nhk.capture.ingest` converge through the
existing Governance lifecycle for a source-specific Source, provenance Claim
and Evidence before Video `about` relation apply. Preserve fail-closed Video
relationship validation, source-specific Evidence isolation, Article/Video
ownership boundaries and Public Identity verification.

## Constraints

- No live, DEMO, V2 or production data mutation; no direct SQL.
- Capture remains an orchestrator; Source, Knowledge and Evidence writes remain
  governed Proposal/Submit/Approval/Eligibility/Controlled Apply/read-back
  operations.
- Do not weaken the Video Relationship Contract or treat a subject UUID,
  source title, or USER_HINT as Evidence.
- The new chain may support only the exact Variant identity relation. Marketing
  claims and conflicting classifications remain excluded.
- Do not backfill Public Identity for existing Videos in this slice.

## Implementation slices

1. Add failing unit/contract tests for the Capture Video dependency chain,
   source-specific Evidence isolation, ambiguous source handling, marketing
   claim isolation, conflicting classification, Article/Video decoupling,
   category planning, stale preflight refresh, compliance and Public Identity.
2. Add a Capture-scoped provenance planner/coordinator that emits governed
   Source/Claim/Evidence proposals, applies/read-backs dependencies first, and
   only then builds the Video semantic attachment with the exact Evidence ref.
3. Keep the existing Video controlled apply and relationship validator as the
   final Video mutation boundary; add canonical Video/about/read-back checks.
4. Allocate and read back a Public Identity only for a newly ingested Video,
   then expose the persisted route as the Video readiness result.
5. Refresh Article owner preflight/category evidence inside Capture and keep
   Article publication blockers independent from Video readiness.
6. Probe the current raw authenticated `tools/list` descriptor read-only and
   classify `capture_id` exposure without invoking continuation.
7. Update the relevant semantic-ingest contract and factual execution state.

## Verification gates

- Focused Capture/Video/Governance/Source/Evidence Unit and Contract tests.
- Available guarded Integration tests, with infrastructure failures reported
  separately from test passes.
- PHP lint, Composer validation, `git diff --check`, and targeted secret scan.
- Inspect diff/status before commit; commit and push only after all available
  gates pass and no live mutation/deployment has occurred.
