# Capture/Governance systemic repair plan

## Goal

Repair the generic `nhk.capture.ingest` lifecycle so an existing Capture can
resume its durable failed checkpoint with the original Capture idempotency key,
while real editorial addenda remain a separate idempotency namespace. Preserve
the current WordPress endpoint revision fail-closed behavior and make the local
runtime/deployment identity evidence explicit. No live or staging mutation is
part of this plan.

## Root-cause evidence

1. `McpTransport::captureIngest()` dispatches every editorial request carrying
   `capture_id` to `EditorialCaptureContinuationService::execute()`.
2. That service always treats the supplied key as an addendum key. A retry with
   the original Capture key therefore cannot reach `EditorialCaptureCoordinator`
   and returns `CAPTURE_ADDENDUM_IDEMPOTENCY_CONFLICT` or creates the wrong
   ledger record.
3. The coordinator already persists enough Capture context and phase receipts
   to resume without replaying physical ingest or draft creation, but there is
   no explicit retry boundary that rehydrates that context and preserves the
   original Capture fingerprint/key.
4. `RelationRevisionBinder` correctly fails closed when the `wp_post` revision
   resolver has no current revision. The current local `WpPostEndpointResolver`
   already falls back from the WordPress zero-date GMT sentinel to native local
   modification time, and `CoreEndpointResolverRegistrar` registers it. A live
   old failure therefore still requires deployment/runtime identity verification;
   this plan does not claim that live runtime was reloaded.

## Design

1. Add a closed Capture orchestration field `resume_mode=RETRY` to the canonical
   Capture schema. It is control-plane routing, not a semantic entity or
   operation type.
2. Route `resume_mode=RETRY` through a dedicated retry method before addendum
   lookup. Require `capture_id` and the exact original Capture idempotency key;
   reject mismatch fail-closed without touching the addendum ledger.
3. Rehydrate retry input only from persisted Capture context, canonical assets,
   article identity/token, diagnostics and phase receipts. Do not accept a new
   text/file/video payload as a retry payload, and do not re-run completed
   physical or draft phases.
4. Retry the durable failed checkpoint through the existing coordinator and
   governed semantic lifecycle. Existing proposals/relations are reused by
   their canonical lifecycle keys; stale relation proposals are rebuilt from
   fresh endpoint revisions; Apply/read-back remains Governance-owned.
5. Keep default `capture_id` behavior as real text/asset addendum, including its
   separate key/fingerprint, governance-only addendum replay, file rejection and
   existing `resume_children` child scope.
6. Persist the minimum body-free original workflow metadata needed for safe
   rehydration (intent, publish/video control and documentation checkpoint);
   Article body remains owned only by `wp_posts`.
7. Extend focused runtime identity tests/evidence to assert the resolver
   registration and current release tuple. Do not add hardcoded live IDs or a
   generic runtime writer.

## TDD sequence

1. Add failing unit tests for retry routing, exact key binding, no addendum
   creation, no physical/draft replay, governance-only resume, repeat retry
   idempotency, invalid retry payloads, and unchanged addendum behavior.
2. Add failing MCP schema/transport tests for `resume_mode` and routing.
3. Implement the smallest service/coordinator/schema changes.
4. Run focused Capture/Graph/Governance tests, then full unit, PHP lint,
   `git diff --check`, and the repository secret review.
5. Update `V3_EXECUTION_STATE.md` with root cause, files, evidence and live
   boundary. Do not deploy, reload PHP-FPM/OPcache, call live Capture, or claim
   live acceptance.

## Acceptance matrix

- Same original Capture key + `resume_mode=RETRY` resumes the same Capture.
- Wrong key, missing Capture, published Capture, changed retry payload and
  unsupported child controls fail closed without addendum creation.
- Retry twice does not create a second Capture, Article, Media, relation or
  proposal; completed phases are reused.
- Governance-only retry restores persisted context/provenance and reaches the
  existing governed lifecycle.
- Text addendum and `ATTACH_ASSETS` paths retain existing idempotency/conflict
  behavior and never masquerade as Capture retry.
- Date-floating `wp_post` endpoint revision fallback remains tested and the
  registrar has one canonical `wp_post` resolver.
- Local runtime identity is coherent; live identity mismatch remains an
  explicit deployment/reload blocker if not externally verified.
