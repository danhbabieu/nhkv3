# Issue #21 audit — Dictionary lexical system

Date: 2026-09-29

This audit maps the 23 normative Dictionary contract criteria plus five Issue
#21 delivery criteria. `LOCAL_PASS` means code/tests prove the boundary in this
checkout; `RUNTIME_BLOCKED` means the target WordPress/MySQL read-back still
requires the guarded `nhk_v3_test` runtime and is not claimed from code
presence.

| # | Requirement | Implementation | Test/evidence | Status | Blocker |
|---:|---|---|---|---|---|
| 1 | Reuse one canonical destination | Resolver + approved Dictionary/PublicRoute owner revalidation | `DictionaryResolverTest`, `DictionaryPublicQueryTest` | LOCAL_PASS / RUNTIME_BLOCKED | Target runtime read-back |
| 2 | Alias resolves to preferred concept | Label repository lookup + normalized resolver | `DictionaryResolverTest`, `DictionaryPublicQueryTest` | LOCAL_PASS | — |
| 3 | Unknown term creates one private candidate | Planner candidate upsert, no semantic writer | `DictionaryPlanningServiceTest`, `DictionaryHarvesterTest` | LOCAL_PASS | — |
| 4 | Ambiguous term never auto-links | Ambiguous resolution produces candidate/review state | `DictionaryResolverTest`, `DictionaryLinkPlannerTest` | LOCAL_PASS | — |
| 5 | Suppression is durable | Candidate `DO_NOT_SUGGEST` lookup blocks recreation | `DictionaryPlanningServiceTest`, migration integration contract | LOCAL_PASS / RUNTIME_BLOCKED | Integration DB unavailable |
| 6 | Longest phrase and unchanged body | Existing link planner/render-time linker | `DictionaryLinkPlannerTest`, `DictionaryHtmlLinkerTest` | LOCAL_PASS | — |
| 7 | Article planning is non-blocking | Article preflight returns `dictionary_plan`, registry preview only | `ArticleDictionaryPlanningTest` | LOCAL_PASS | — |
| 8 | Knowledge reuse does not duplicate claims | Resolver reads Knowledge; Dictionary observer only records lexical mention/candidate | `DictionaryRuntimeContractTest`, `DictionaryWriteObservationTest` | LOCAL_PASS / RUNTIME_BLOCKED | Target read-back |
| 9 | Media weak observations stay lexical | Attachment bridge marks title/alt/filename as weak sources | `DictionaryMediaObservationBoundaryTest` | LOCAL_PASS | — |
| 10 | Video observations preserve target scope | Video observer enters shared Dictionary registry; no semantic write path | `DictionaryWriteObservationTest`, `DictionaryHarvesterWiringTest` | LOCAL_PASS | Runtime candidate read-back |
| 11 | Search exposes approved aliases only | MCP search reads `DictionaryPublicQuery::hub` labels | `McpDictionaryToolsContractTest`, `DictionaryPublicQueryTest` | LOCAL_PASS | — |
| 12 | Delegated entries do not create competing pages | Public query returns delegated destination and `indexable=false` | `DictionaryPublicQueryTest` | LOCAL_PASS / RUNTIME_BLOCKED | Route read-back |
| 13 | Dedicated page only for Dictionary-owned destination | `detail()` returns `REDIRECT` for delegated concepts | `DictionaryPublicQueryTest` | LOCAL_PASS | — |
| 14 | Dedicated page canonical/indexability | Public route emits canonical + DefinedTerm and 404s incomplete/private entries | `DictionaryPublicQueryTest`, existing frontend contract | LOCAL_PASS / RUNTIME_BLOCKED | Browser/runtime unavailable |
| 15 | Contextual projections are approved/deduplicated | WordPress bridge/home/search/link projection consumes public hub | `FrontendPresentationContractTest`, `DictionaryHtmlLinkerTest` | LOCAL_PASS / RUNTIME_BLOCKED | Browser/runtime unavailable |
| 16 | Delegated links go directly to current owner | Destination validator runs at public-query read time | `DictionaryPublicQueryTest`, `DictionaryRuntimeContractTest` | LOCAL_PASS | — |
| 17 | Lexical edits do not rekey owner | Mutation service changes Concept/Label only; owner IDs remain fields | `DictionaryMutationContractTest`, `DictionaryCurationServiceTest` | LOCAL_PASS | — |
| 18 | Private state absent from public projections | Public query lists approved active concepts/labels only | `DictionaryPublicQueryTest`, `DictionaryRuntimeContractTest` | LOCAL_PASS | — |
| 19 | Curated writes auth/revision/idempotency/read-back | MCP policy + mutation service + audit receipts + Admin capability | `DictionaryMutationContractTest`, `McpContractTest`, `DictionaryAdminLifecycleBoundaryTest` | LOCAL_PASS / RUNTIME_BLOCKED | Audit/read-back integration |
| 20 | Replay-safe scans/projections | Mention fingerprint and candidate term/context unique keys | `DictionaryBackfillDryRunTest`, `DictionaryPlanningServiceTest`, migration integration contract | LOCAL_PASS / RUNTIME_BLOCKED | Integration DB unavailable |
| 21 | URL surfaces agree | Shared `DictionaryPublicQuery` feeds route/head/sitemap/link paths | `DictionaryPublicQueryTest`, frontend contract tests | LOCAL_PASS / RUNTIME_BLOCKED | Browser/runtime unavailable |
| 22 | No implicit semantic truth | Harvester/DictionaryMutation/Handoff have `semantic_write=0`; Graph only receives handoff | `DictionaryHarvesterTest`, `DictionaryRelationHandoffTest`, `McpDictionaryToolsContractTest` | LOCAL_PASS | — |
| 23 | Unavailable is not empty success | Runtime/MCP/Admin return explicit unavailable states | `ArticleDictionaryPlanningTest`, `DictionaryBackfillDryRunTest`, `McpDictionaryToolsContractTest` | LOCAL_PASS | — |
| 24 | Harvester is wired to canonical Article/Knowledge/Media/Video paths | Bootstrap registers one Harvester in `DictionaryObservationRegistry`; Article preview, Knowledge/Video writes and WordPress Media/Article bridge reuse it | `DictionaryHarvesterWiringTest` | LOCAL_PASS | Runtime observation read-back |
| 25 | MCP covers search/resolve/detail/labels/candidates/mentions/profile/CRUD/review/handoff/dry-run | Catalog, dispatch, transport and Ability projection now expose 14 Dictionary tools | `McpContractTest`, `McpDictionaryToolsContractTest` | LOCAL_PASS / RUNTIME_BLOCKED | Live discovery not available |
| 26 | Owner-backed projection reaches Graph/Knowledge/Media/Video/Coverage without copying truth | Dictionary profile reports readiness/coverage; existing owner services remain authoritative | `DictionaryRuntimeContractTest`, owner/public projection tests | LOCAL_PASS / RUNTIME_BLOCKED | Target data graph/read-back |
| 27 | Admin curator UX covers queue, source context, edit, lifecycle and dry-run | Existing Dictionary curator plus lifecycle edit and dry-run pages | `DictionaryAdminLifecycleBoundaryTest`, `DictionaryBackfillAdminBoundaryTest`, Admin workbench tests | LOCAL_PASS / RUNTIME_BLOCKED | Browser/runtime unavailable |
| 28 | Dry-run/backfill is bounded and non-mutating | `DictionaryBackfillDryRun`, MCP/Admin dry-run, source counts and no-write marker | `DictionaryBackfillDryRunTest`, `DictionaryBackfillAdminBoundaryTest` | LOCAL_PASS / ENVIRONMENT_BLOCKED | Local WP runtime unavailable; no data scan executed |

## Runtime audit

The requested live dry-run was not executed: this checkout has no configured
`NHK_WP_TEST_PATH`/`nhk_v3_test` WordPress integration runtime. No semantic,
public, staging or production data was written.

The two KnowledgeWriter failures were verified against the immediate pre-Issue
#21 baseline commit `6c57edf6` in a temporary worktree. Both failed with the
same assertion before Issue #21 changes, so they are pre-existing and not a
Dictionary regression.

Final full PHPUnit invocation: 2,917 tests / 16,485 assertions, 23 failures,
19 warnings, 46 deprecations, 49 PHPUnit deprecations and 122 skips. The 23
failures are exactly the two baseline KnowledgeWriter failures plus 21 guarded
integration failures caused by the unavailable WordPress/MySQL test runtime.
