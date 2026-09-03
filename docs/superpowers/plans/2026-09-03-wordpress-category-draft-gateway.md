# WordPress Category and Editorial Draft Gateway

Status: implementation plan — 2026-09-03

## Guardrails

- Preserve all existing working-tree changes and concurrent Semantic Merge/Ô Đô work.
- No publish, trash/delete of live data, production/staging/V2 access, semantic record, Knowledge, Authority, Media, Video or Graph mutation.
- Use `nhk_v3_test` only for guarded integration tests; an unavailable database remains `IMPLEMENTATION_READY_RUNTIME_UNVERIFIED`.
- WordPress native taxonomy and `wp_posts` remain the sole owners of their respective editorial data.
- Reuse the existing Article operation receipt/idempotency boundary; never store Article body in receipts.

## Slice A — Category Gateway (independently reviewable)

1. Audit current MCP catalog, ability bridge, WordPress/editorial adapters and receipt conventions.
2. RED: add focused unit/contract tests for deterministic category resolution, idempotent create, parent validation, fingerprint CAS update, assignment/unassignment, guarded delete and transport delegation/no semantic writes.
3. GREEN: implement a typed `WordPressCategoryGateway` application contract and WordPress adapter using native category functions. Return native identity, canonical state/fingerprint, parent, usage/count and affected post read-back. Fail closed on ID/slug/name conflicts, unsafe deletion and stale expected state.
4. Register only approved typed category operations in the MCP catalog/capability manifest and add an Ability bridge only if the existing client-facing registration path requires it; the bridge delegates to the application boundary.
5. Add guarded integration coverage without fallback to `nhk_v3`, run focused/full unit, lint, Composer, diff/secret checks, update contracts/execution state, and commit only Slice A.

## Slice B — Editorial Draft Gateway (independently reviewable)

1. RED: add focused tests for draft-only create, receipt idempotency/recovery, no body persistence, native state-token CAS update, research binding, category delegation, incomplete publication diagnostics and explicit unsupported publish/trash.
2. GREEN: implement shared typed editorial application boundary and WordPress adapter for read/create-draft/update-draft. Reuse `WpdbArticleOperationReceiptRepository`; resolve receipt/Post before retrying creation; never blind-overwrite or publish.
3. Extend existing Article preflight/ingest orchestration only for create/update draft intents while preserving reconcile behavior and governed semantic apply separation. Expose research runtime caveats honestly and keep media/semantic writes out of the draft writer.
4. Update catalog/manifest and Ability bridge only for actually implemented typed operations; no generic WordPress writer or publish capability.
5. Add guarded integration coverage, run all quality gates, update contracts/execution state/parity evidence, and commit only Slice B.

## Review gates

- Each slice must show RED before implementation, focused and full unit evidence, guarded integration attempt, PHP lint, Composer validation, `git diff --check`, secret review and final status.
- If a requested surface is not authorized by an existing runtime registry/contract, record the gap and fail closed rather than inventing it.
- Final report must give separate Category and Draft statuses, plan path, both commit SHAs, changed files, test/assertion counts, DB blocker, capability/Ability changes and remaining unsupported operations.
