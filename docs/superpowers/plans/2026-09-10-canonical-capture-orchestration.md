# Canonical Capture orchestration convergence

## Scope

Converge the existing `nhk.capture.ingest` boundary around one canonical
subject-resolution handoff. Preserve the Constitution's owner boundaries:
Capture coordinates, Authority resolves identity, Graph/Knowledge are read for
bounded reuse, Media and Video retain their owners, and Governance is the only
durable semantic mutation path. Do not mutate DEMO, production, staging, V2 or
live Article 355.

## Root cause established before implementation

1. Capture uses `CanonicalAuthoritySubjectResolver`, semantic resolution uses a
   separate resolver, and MCP search uses substring discovery. The surfaces do
   not share a typed immutable resolution context.
2. The Capture physical-ingest callback enriches Video before Capture subject
   resolution. Video then selects a broader textual match such as Model Odo 36.
3. Article Media reconciliation imports current WordPress usage and permits a
   global reusable-media fallback without a persisted subject-scope lock.
4. Blueprint validation exceptions are caught by the Capture-wide Throwable
   handler and reported as `FAILED_RETRYABLE`, conflating contract/system errors
   with ordinary missing-media readiness.
5. `capture_id` is already present in the catalog and transport continuation
   route; parity must be verified across descriptors, tests and documentation.

## Implementation slices (TDD)

1. Add failing resolver and handoff tests for exact Variant identity,
   UUID-first precedence, typed resolution metadata, and Video consumption of
   the parent subject without textual downgrade. Implement the smallest shared
   resolver/handoff change and rerun focused tests.
2. Add failing Media tests for text+Video with no files, stale WordPress usage,
   empty/ambiguous resolution, and persisted subject-scope enforcement. Make
   historical reuse require a locked non-empty persisted subject match; retain
   governed placeholders and honest incomplete diagnostics.
3. Add failing Video tests for canonical external identity reuse and relation
   planning with the resolved Variant. Keep `about` direction and evidence
   requirements from the current registries/contracts; do not add a predicate.
4. Add Claim retrieval tests for bounded existing-Claim reuse, original scope,
   provenance, evidence and relevance. Add atomization tests for explicit user
   statements so specimen/video observations remain scoped and recognition
   wording remains attributed or review-required.
5. Verify continuation parity for `capture_id`, same-key idempotency and changed
   payload conflict across schema, catalog, transport, descriptor and tests.
6. Replace broad retry classification only where current vocabulary already
   represents readiness/review/system failure; preserve fail-closed behavior and
   never convert missing media or transcript absence into infrastructure retry.
7. Run focused, Unit, Contract and available Integration suites; run PHP lint,
   diff checks and repository secret checks. Treat unavailable Integration DB or
   runtime as BLOCKED, never PASS.
8. Update only affected ACTIVE contracts and execution state, then run the
   canonical `composer generate:mcp-docs` projection build. Verify manifest,
   documentation version, manifest hash, bootstrap, list/get and stale-checkpoint
   behavior.

## Acceptance evidence

- The Odo 36/10 hint resolves to Variant `95873bfe-d978-4eda-a5a2-ce9ba79625df`
  with stable key `nhk:variant:odo.36.10` in deterministic tests.
- Child Video/Claim/Media/composition consumers receive the same resolved
  subject packet and do not broaden it.
- Attachments 297/298 cannot be selected without eligible persisted subject
  scope; missing media produces placeholder/incomplete state.
- Existing Video and Knowledge identities are reused before proposal/create,
  and all durable semantic changes remain governed.
- Existing Capture continuation preserves one Capture and one Article draft.
- No live replay, deployment, publication or external mutation is claimed.
