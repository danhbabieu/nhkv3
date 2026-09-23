# SDD ledger — plan: docs/superpowers/plans/2026-09-23-capture-pipeline-convergence.md

## Setup

- Execution mode: subagent-driven, sequential.
- Workspace: current repository fallback because native worktree scratch writes were denied by sandbox; user explicitly authorized implementation.
- Base commit before Task 1: `a88ab8fd08a017ef504a3f6a0d545786ed5924bb`.
- Spec: `docs/superpowers/specs/2026-09-23-capture-pipeline-convergence-design.md`.
- Plan: `docs/superpowers/plans/2026-09-23-capture-pipeline-convergence.md`.
- No V2/production/staging mutation is authorized before Task 10 and its gates.

## Pre-flight plan conflict scan

| Scope | Shared interface/file | Finding | Ruling |
|---|---|---|---|
| Task 1 → Task 2 | `EditorialCaptureCoordinator`, `EditorialCaptureContinuationService` | Task 1 narrows Video dispatch; Task 2 preserves Video-only reconciliation. Compatible. | Apply in order; Task 2 must consume the narrowed intent boundary. |
| Task 1 → Task 4 | Coordinator Video fallback vs Article MediaUsage | Video thumbnail fallback must not become Article MediaUsage for non-Video intents. | Keep fallback guarded by resolved `VIDEO`; Task 4 tests this. |
| Task 2 → Task 3 | Subject packet and staging packet | Both packets bind downstream work to current Capture state; no conflicting owner. | Subject packet remains semantic identity; staging packet remains authorization context. |
| Task 3 → Task 4 | `staging_acceptance` through `MediaBindingService` | Task 4 depends on Task 3's exact signed context. | Do not add a second scope mechanism. |
| Task 4 → Task 5 | Article MediaUsage may rotate editorial token used by Article fields. | Task 5 must read current state, not pre-Media snapshot. | Task 6 will enforce final retry rehydration; Task 5 compares the token it receives. |
| Task 5 → Task 6 | Article field mapping/read-back and state token | Task 6 consumes the canonical token produced by Task 5. Compatible. | Preserve native `EditorialPostState` as the only Article state source. |
| Task 6 → Task 7 | Current token and receipts feed publication/completion. | Stale tokens would invalidate gate evidence. | Task 7 may only consume refreshed read-back. |
| Task 7 → Task 8 | Gate/completion/URL invariants become regression expectations. | No conflict. | Task 8 tests all six intents and retry/idempotency matrix. |
| Task 8 → Task 9 | Documentation must describe tested behavior only. | No conflict. | Update contracts only where executable behavior changed. |
| Task 9 → Task 10 | Runtime identity/documentation checkpoint gates staging. | No conflict. | Task 10 remains fail-closed if any identity/credential/scope is unavailable. |
| Every task | Constitution/global constraints | No task authorizes direct writer, direct DB semantic write, V2/production mutation or hard-coded fixture logic. | Stop on any implementation deviation. |

| Task | Internal consistency scan | Ruling |
|---|---|---|
| 1 | Tests target coordinator intent dispatch and listed files; pass gate is explicit. | Clean. |
| 2 | Tests target packet precedence/continuation and listed services. | Clean. |
| 3 | Tests target signed scope propagation and governed apply. | Clean. |
| 4 | Tests target Media/Usage/projection convergence. | Clean. |
| 5 | Tests target aliases, native fields and read-back. | Clean. |
| 6 | Tests target token races, retry and receipt convergence. | Clean. |
| 7 | Tests target gate, route and completion evidence. | Clean. |
| 8 | Tests cover all six intents and interruption/idempotency. | Clean. |
| 9 | Files are contract/evidence docs only; no invented vocabulary permitted. | Clean. |
| 10 | External verification is bounded and fail-closed; no autonomous cutover. | Clean. |

## Task status

- Ruling: the first Task 1 subagent exceeded the available subagent quota and returned no final status, but its workspace contained Task 1 edits plus two unrelated committed hierarchical-subject-resolution documentation commits. The unrelated docs commits were outside the approved plan; their files were removed from the working tree, the unrelated subject-packet test was removed from Task 1, and the Task 1 implementation remains under direct review. The fallback is this controller session completing Task 1 with the same TDD/review gates; no architecture change is implied.
- Task 1: complete — implementation and controller review PASS; subagent quota prevented the planned separate reviewer, so controller performed the independent diff/test/scope review. Focused suite: 108 tests / 549 assertions; full Unit: 2,312 tests / 13,498 assertions; changed-file PHP lint and `git diff --check` pass.
- Task 1 ruling: the implementation gates Video enrichment, Video subject hints/resume, Video publication verification and thumbnail fallback by resolved `VIDEO` intent plus an actual Video owner/input. Non-Video paths retain neutral Video diagnostics. The unrelated hierarchical-subject-resolution commits/files were removed from the working tree before Task 1 commit; no plan/Constitution change is required.
- Task 2: complete — controller implementation/review PASS. The planned separate subagent/reviewer remained unavailable because the prior subagent exhausted quota; controller used red-first TDD, focused/full test gates, diff/scope review and six-intent regression review.
- Task 2 root cause/ruling: `SubjectResolutionService::resolveSources()` fell through from an unresolved explicit canonical UUID to weaker stable-key/hint inference. It now fails closed for supplied canonical UUID or stable key when no canonical match is read back. Preparation fixtures were corrected to return the canonical entity for supplied UUIDs, preserving the contract rather than relying on fallback behavior.
- Task 2 evidence: targeted red test failed as expected (`resolved` instead of `unresolved`) before the production fix; focused suite passed 110 tests / 539 assertions; full NHK Unit passed 2,314 tests / 13,505 assertions; changed-file PHP lint and `git diff --check` passed. IMAGE_ARTICLE, TEXT_ARTICLE and VIDEO capture paths retain subject packet behavior; MEDIA_ENRICHMENT, KNOWLEDGE_DELTA and KNOWLEDGE_REPAIR remain outside weaker fallback and passed the same regression suite. No staging/live mutation.
- Task 3: complete — implementation audit/review PASS with no product-code change. Existing shared scope propagation satisfies the approved invariant: server-issued Capture-bound packet is persisted and carried through governed proposal/read-back boundaries; missing, stale, tampered, guessed or mismatched scope fails closed. The unrelated hierarchical-subject scratch files were removed in cleanup commit `de32d339`.
- Task 3 evidence: staging-focused suite passed 50 tests / 101 assertions; `git diff --check` passed; no database, staging/live, V2 or production mutation. Regression review: IMAGE_ARTICLE/TEXT_ARTICLE/MEDIA_ENRICHMENT Media scope paths remain exact; VIDEO scope remains separate; KNOWLEDGE_DELTA/KNOWLEDGE_REPAIR dependency admission remains Capture/intent-bound and does not receive Media scope.
- Task 4: pending.
- Task 3: pending.
- Task 4: pending.
- Task 5: pending.
- Task 6: pending.
- Task 7: pending.
- Task 8: pending.
- Task 9: pending.
- Task 10: pending.
