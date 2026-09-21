# NHK V3 Unified Canonical Evolution — Phase 1 to Phase 8 Local Evidence

> EVIDENCE ONLY — 2026-09-21. This file records disposition application,
> impact mapping, local regression evidence and the Phase 8 handoff. It is not
> the Constitution, an ACTIVE semantic contract, or a second Master Plan.
> The official dispositions were supplied by the program instruction that
> initiated this checkpoint.

## Execution identity

```text
PROGRAM=NHK_V3_UNIFIED_CANONICAL_EVOLUTION
BASELINE_SOURCE_REVISION=340c7493d06135d589b3f8e61310ac40080981f1
OBSERVED_SOURCE_REVISION=eddc3d18e587233391fb1e9b7d71a41866e80ca6
TARGET_ENVIRONMENT=LOCAL_REPOSITORY_AND_LOCAL_TEST_RUNTIME
BUILD_IDENTITY=NOT_REBUILT_IN_THIS_DOCUMENTATION_CHECKPOINT
MUTATION=NO_SEMANTIC_SCHEMA_DATABASE_DEPLOYMENT_OR_LIVE_URL_MUTATION
```

## Phase 1 decision matrix

| Finding | Disposition | Canonical policy owner | Documents changed/referenced | Implementation impact | Test impact | Deferred phase | Human decision required |
|---|---|---|---|---|---|---|---|
| CCF-01 | CONSOLIDATE | `CompletionCoordinator` for completion policy; Capture coordinator/continuation for orchestration; MCP for transport | Article Ingest, MCP Content Operations, Control Plane | No new domain completion service; preserve existing shared coordinator | Existing completion, Capture convergence and MCP boundary suites | None for local policy boundary | No |
| CCF-02 | CONSOLIDATE | `SemanticSuitabilityPolicy`; `RepresentativeEligibilityRegistry`; Media services persist identity/usage | 04_MEDIA_MODEL, P6 Media/Video, Article Media contracts | Article consumes suitability; MCP/P6 do not redefine it | Suitability, representative reconciliation and Article Media suites | Schema proof only if Phase 5 finds a representational gap | No |
| DPO-01 | CONSOLIDATE | `CompletionCoordinator` | Article Ingest, Capture contracts, completion tests | Diagnostics may remain distributed; COMPLETE/PARTIAL/BLOCKED rule is shared | `CompletionConvergenceTest`, Capture convergence suites | None | No |
| DPO-02 | CLARIFY_AND_KEEP | Governance policy/eligibility; `ControlledApplyService`; owner repositories; MCP adapter | P4 Governance, MCP contracts | No policy duplication in MCP; no new writer | Governance Apply/Eligibility and MCP gate suites | None | No |
| IDR-01 | REFRAME | Runtime registry/catalog + runtime registration; client exposure and successful call remain separate facts | MCP Content Operations, Control Plane, status index | Conformance evidence may be generated/test-derived; no hand list as truth | Manifest, discovery/execution parity and transport suites | Phase 8 live connector proof | No |
| IDR-02 | CLARIFY_AND_KEEP | Persisted Public Identity service/repository plus separate runtime/data/consumer/readback gates | Public Identity matrix, route audit, status index | Do not claim live rollout from source presence | Public Identity and route/readiness suites | Phase 8 live acceptance | No |
| USC-01 | ADD_TO_EXISTING_CONTRACTS | Knowledge contract; Source/Evidence preserve provenance | Knowledge/Source model, Living Knowledge, Article preflight | Scope/generalization matrix added as a contract clarification | Knowledge scope/provenance tests | None for local read/write boundary | No |
| USC-02 | ADD_AND_CLARIFY | Governance policy and Capture continuation classification | Governance, Article Ingest, Capture continuation | Per-scope classification; execution fingerprint alone is not materiality | Continuation/Governance tests | Phase 4 representative proof | No |
| USC-03 | CLARIFY | Related projection query boundary + Graph registry | Related Semantic Projection, Graph Core, public dossier | Read-only direct/derived/contextual/editorial classification; no shortcut edges | Related query/projection tests | Phase 6/7 implementation gaps | No |
| SWC-01 | ADD_GENERATED_CONFORMANCE_VIEW | Executable catalog/registry/manifest | MCP contracts and runtime evidence | No new canonical operation list | Capability manifest/discovery parity tests | Phase 8 connector proof | No |
| SWC-02 | CONSOLIDATE | Existing Article contracts + `ArticleComposer`/managed-section boundary | Article Ingest, Article research preflight, Knowledge contract | Preserve human prose; regenerate only managed sections | Composer/managed-section/claim-trace tests | Phase 7 complete projection scope | No |
| CWI-01 | DEFER_IMPLEMENTATION_TO_PHASE_7 | SEO projection contracts | Article SEO/SEO Blueprint contracts | No speculative full planner implementation in Phase 1–6 | Contract and existing SEO projection tests | Phase 7 | No |
| CWI-02 | DEFER_IMPLEMENTATION_TO_PHASE_6_OR_7 | Related query/projection boundary | Related Semantic Projection | Acceptance contract first; minimal implementation only | Related query tests | Phase 6/7 | No |
| CWI-03 | SPLIT_AND_DEFER | Graph registry for Product–Specimen; Public Identity activation boundary | Identity matrix, route audit, P5/P6 contracts | No guessed relation or bulk activation | Boundary/readiness tests only | Phase 6 and Phase 8 | Yes only if live activation/identity change is requested |
| GSR-01 | PHASE_2_IMPACT / PHASE_4_REFACTOR_IF_PROVEN | Capture orchestration vs domain policy owners | Capture source and continuation tests | Audit responsibility crossings; no line-count refactor | Capture convergence and coordinator tests | Phase 2/4 | No |
| GSR-02 | PHASE_2_IMPACT | MCP composition root may wire, not own semantic policy | MCP transport/catalog/plugin source/tests | Classify composition separately from policy | MCP boundary/discovery tests | Phase 2 | No |
| HDR-01/02 | KEEP_AS_HISTORICAL_EVIDENCE | Current status index/registry classification | Historical Ability exposure and dated execution evidence | No deletion or promotion to current law | Documentation classification checks | None | No |

## Phase 2 — system-wide impact map

| Requirement | Principle | Current source | Current tests/evidence | Classification | Schema/data/API impact | Rollback boundary |
|---|---|---|---|---|---|---|
| One completion policy | One policy owner; orchestration remains separate | `Application/Completion/CompletionCoordinator.php`; Capture coordinators; MCP adapters | `CompletionConvergenceTest`, `EditorialCaptureConvergenceE2ETest`, Capture continuation suites | NO_CHANGE / TEST_ONLY | None | Revert documentation-only contract clarifications |
| Shared Media suitability | Media owns identity; usage owns presentation context | `Application/Media/SemanticSuitabilityPolicy.php`; `Domain/Media/RepresentativeEligibilityRegistry.php`; Media binding/reconciler | `SemanticSuitabilityAndProjectionTest`, `RepresentativeMediaReconcilerTest`, `ArticleMediaPolicyTest` | NO_CHANGE / TEST_ONLY | None | Existing policy boundary |
| Governance separation | Governance owns legality; Apply executes | `Application/Governance/ControlledApplyService.php`, eligibility/policy services | Governance core/apply/queue/MCP gate suites | NO_CHANGE | None | Existing Proposal/Apply boundary |
| Knowledge scope/generalization | Narrow facts do not widen | Knowledge domain, enrichment planner, Article claim selection | Knowledge scope/provenance and Article research tests | TEST_ONLY / DOCUMENTED_CONFORMANCE | No migration | Existing canonical Claim/Source/Evidence boundary |
| Read-only related projection | Reachability is discovery only | Related query/read services, Graph registry | `RelatedSemanticQueryTest`, `RelatedContentQueryTest`, dossier tests | NO_CHANGE / TEST_ONLY | No edges, no schema | Read projection only |
| Article managed composition | Article is native WP editorial truth | `Application/Semantic/ArticleComposer.php`, managed-section parser | composer, preflight, claim projection tests | NO_CHANGE / TEST_ONLY | No new Article entity/body store | Managed-section ownership |
| Capability truth separation | Registry/runtime/client facts remain distinct | MCP catalog, manifest, registration, transport | `McpCapabilityManifestTest`, `McpDiscoveryExecutionParityTest`, transport tests | TEST_ONLY / PHASE 8 runtime | No semantic API change | Catalog/manifest generation boundary |

## Phase 2 responsibility audit

| Component | Primary responsibility | Crossed boundary observed | Refactor required now |
|---|---|---|---|
| `EditorialCaptureCoordinator` | ORCHESTRATION | Calls multiple domain ports and completion aggregation | NO; evidence does not prove policy ownership leakage |
| `GovernedCaptureContinuationService` | ORCHESTRATION / CONVERGENCE | Rehydrates Capture children and completion | NO; preserve shared coordinator |
| `CompletionCoordinator` | COMPLETION POLICY / DERIVED READ RESULT | Aggregates owner read-back and completion states | NO; selected canonical policy owner |
| `GovernanceService` / policy services | GOVERNANCE | Proposal legality, approval, eligibility | NO |
| `ControlledApplyService` | CONTROLLED APPLY / TRANSACTION | Executes authorized mutation and read-back | NO |
| `McpTransport` | TRANSPORT / ADMISSION | Dispatch and validation | NO; no semantic policy found in this audit |
| `McpAbilityRegistration` / `Plugin.php` | RUNTIME REGISTRATION / COMPOSITION ROOT | Wires many dependencies | NO; concentration alone is not god-service evidence |
| `ArticleComposer` | EDITORIAL PROJECTION / MANAGED CONTENT | Reads bounded claims/observations | NO; preserves managed-section boundary |
| Media reconciliation services | MEDIA IDENTITY + USAGE PERSISTENCE | Consume shared suitability/eligibility primitives | NO |
| SEO projection services | DERIVED PROJECTION | Full Blueprint planner remains incomplete | DEFER to Phase 7 |

## Phase 3 — regression and compatibility safety net

The required invariant families are already represented by the following
current test groups. No assertion was weakened and no baseline failure was
converted into success:

| Invariant family | Protective evidence |
|---|---|
| Capture identity/continuation/read-back | `CompletionConvergenceTest`, `EditorialCaptureContinuationTest`, `GovernedCaptureContinuationServiceTest`, `CaptureCanonicalReadbackIntegrationTest` |
| Governance approval/CAS/idempotency | `GovernanceCoreTest`, `GovernanceApplyContractTest`, `P4GovernanceAcceptanceIntegrationTest`, proposal/eligibility suites |
| Knowledge scope/provenance | `P7KnowledgeTest`, `P7KnowledgeIntegrationTest`, `CanonicalKnowledgeEvidenceAuditReaderTest`, living-knowledge tests |
| Media suitability/representative usage | `SemanticSuitabilityAndProjectionTest`, `ArticleMediaPolicyTest`, `RepresentativeMediaReconcilerTest`, `MediaServiceUsageIdentityTest` |
| Graph direct/derived/read-only projection | `GraphCoreContractTest`, `RelatedSemanticQueryTest`, `RelatedContentQueryTest`, Graph integration suites |
| Article managed composition/claim trace | Article composer/preflight, semantic claim projection and Capture Article handoff suites |
| Public Identity/route stability | Public Identity service/contract/readiness/route and canary projection tests |
| MCP capability/runtime distinctions | MCP manifest, discovery/execution parity, transport boundary and contract suites |

## Local Phase 4–7 implementation disposition

The source audit found existing shared primitives and regression coverage for
the dispositions above. No additional production implementation was proven
necessary without either inventing a new contract, changing schema, or
entering a deferred gap. Therefore this local pass records **NO_SOURCE_CHANGE**
for the Phase 4–7 work that can be safely completed from the repository:

- Phase 4: existing Capture convergence is canonical-state/read-back driven;
  no domain-specific retry service is introduced.
- Phase 5: existing suitability/usage and Knowledge scope boundaries remain;
  no migration or backfill is authorized.
- Phase 6: related projection remains bounded/read-only; Product–Specimen and
  unregistered graph growth remain fail-closed.
- Phase 7: Article composition preserves managed regions and human prose;
  full SEO Blueprint planner remains deferred under CWI-01.

This is not a claim that all runtime/live parity is complete. It is a local
source-and-test conclusion that further production code would be speculative
at this checkpoint.

## Phase 8 local/shadow preparation

Completed locally/read-only:

1. Current source and test conformance trace.
2. Unit and Contract suite verification: NHK Unit `2025/9953` PASS; NHK
   Contract `6/48` PASS; focused cross-domain boundary set `76/282` PASS.
   Warnings and deprecations are non-failing.
3. Documentation/contract disposition consistency review.
4. Historical-vs-current capability classification.
5. Mutation/deploy boundary review.
6. Exact live handoff scope listed below.

Integration suite was attempted but 14 tests failed at the mandatory
`NHK_WP_TEST_PATH=public` environment gate and 115 tests were skipped; this is
classified as `ENVIRONMENT_UNAVAILABLE`, not a source regression. The Unit and
Contract suites remain green. Not performed: deploy, staging mutation, production mutation, URL reprojection,
bulk migration/backfill, live connector acceptance or external MCP invocation.

### PHASE8_LIVE_ACCEPTANCE_HANDOFF

| Area | Read-only check | Mutation-required check | Authorization |
|---|---|---|---|
| Documentation/runtime | Bootstrap, manifest hash, build identity and ACTIVE contract read-back | None | Read-only allowed |
| Capture | Discover `nhk.capture.ingest/get`, inspect exact Capture and continuation/read-back | New submission or continuation | HUMAN_AUTHORIZATION_REQUIRED |
| Resolve/reconcile | Resolve canonical IDs, inspect bounded neighborhood and duplicate candidates | Apply relation/representative choice | HUMAN_AUTHORIZATION_REQUIRED |
| Governance | Inspect Proposal/Approval/Eligibility/ApplyAttempt state | Controlled Apply | HUMAN_AUTHORIZATION_REQUIRED |
| Graph | Read typed edges and projection paths | Add relation edge | HUMAN_AUTHORIZATION_REQUIRED |
| Article/SEO/frontend | Read native Post, managed sections, routes, metadata and public eligibility | Draft/update/publish or SEO reprojection | HUMAN_AUTHORIZATION_REQUIRED |
| Public Identity | Read persisted identity/history and route readiness | Allocate/change slug or redirect | HUMAN_AUTHORIZATION_REQUIRED |

## Gate results

```text
GATE_1=PASS_LOCAL_DOCUMENTATION_AND_SOURCE_CONFORMANCE
GATE_2=PASS_LOCAL_IMPACT_MAP_AND_RESPONSIBILITY_AUDIT
GATE_3=PASS_LOCAL_PROTECTIVE_COVERAGE_BASELINE
GATE_4=PASS_LOCAL_EXISTING_CONVERGENCE_BOUNDARY
GATE_5=PASS_LOCAL_SCOPED_USAGE_BOUNDARY
GATE_6=PASS_LOCAL_READ_ONLY_GRAPH_PROJECTION_BOUNDARY; WRITE_GROWTH_DEFERRED
GATE_7=PASS_LOCAL_ARTICLE_COMPOSITION_BOUNDARY; FULL_SEO_BLUEPRINT_DEFERRED
GATE_8_LOCAL=PASS_LOCAL_SHADOW_PREPARATION; LIVE_ACCEPTANCE_PENDING
```

## Open risks and deferred items

- Full SEO Blueprint planner/projection remains a Phase 7 implementation gap.
- Related Semantic Projection full page-level convergence remains deferred to
  Phase 6/7 acceptance scope.
- Product–Specimen relation remains a registry/contract gap; no workaround is
  authorized.
- Public Identity implementation is present but live activation/read-back is
  not claimed.
- Connector discovery and deployed runtime parity remain environment facts,
  not repository facts.

## Exit result

```text
EXIT_RESULT=PHASE8_LOCAL_SHADOW_PREPARATION_COMPLETE
LIVE_ACCEPTANCE=REQUIRES_CHATGPT/MCP_READ_ONLY_VERIFICATION_AND_SEPARATE_HUMAN_AUTHORIZATION_FOR_MUTATION
NEXT_EXACT_ACTION=SEPARATE_RELEASE/CUTOVER_ACCEPTANCE; NO_DEPLOYMENT_OR_LIVE_MUTATION_PERFORMED
```

## Final release reconciliation — staging read-only evidence

The following values were supplied from the fresh staging MCP read-only
acceptance and reconciled against the local Git object database. They are
recorded as evidence, not as permission to mutate or deploy.

```text
STAGING_ENVIRONMENT=staging
STAGING_SITE=https://demo.1945.vn
STAGING_SOURCE_REVISION=1c2e0f1b0c32bed1f6de4fe5523635171d481cc0
LOCAL_SOURCE_REVISION=eddc3d18e587233391fb1e9b7d71a41866e80ca6
MERGE_BASE=eddc3d18e587233391fb1e9b7d71a41866e80ca6
LOCAL_CONTAINS_STAGING=NO
STAGING_CONTAINS_LOCAL=YES
REVISION_RELATIONSHIP=STAGING_AHEAD
REVISION_CLASSIFICATION=PROGRAM_DOCUMENTATION_ONLY_DELTA
PRODUCTION_SOURCE_DIFF=NONE
CONTRACT_DOCUMENTATION_PARITY=PASS
LIVE_READ_ONLY_ACCEPTANCE=PASS
LIVE_MUTATION_ACCEPTANCE=NOT_RUN
MUTATION_COUNT=0
DEPLOYMENT_PERFORMED=NO
PUBLIC_IDENTITY_SAMPLE=PASS
PUBLIC_IDENTITY_GLOBAL_COVERAGE=NOT_PROVEN
FULL_DEPLOYMENT_PARITY=NOT_PROVEN
```

The staging-only commit is `1c2e0f1b` and contains only the 12 documentation
paths listed by `git diff --name-status eddc3d18..1c2e0f1b`. No production PHP,
schema, migration, test or runtime source path differs between the two
revisions.

### Contract hash reconciliation

| Path | Local SHA-256 | Staging SHA-256 | Match |
|---|---|---|---|
| `docs/architecture/ARTICLE_INGEST_CONTRACT.md` | `a19a18573de33917b6f9b9ecb74717186c60f9a2f726a11e732e3979fafdf7ce` | `a19a18573de33917b6f9b9ecb74717186c60f9a2f726a11e732e3979fafdf7ce` | YES |
| `docs/architecture/04_MEDIA_MODEL.md` | `b2a26764642c8dbc04e2fb70d62c1a8370fe79ab504db9160e58716cfa58a4ba` | `b2a26764642c8dbc04e2fb70d62c1a8370fe79ab504db9160e58716cfa58a4ba` | YES |
| `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md` | `06495cadf10c57759cfb242638da25715fb14b55f895c6b11f10b333f4877985` | `06495cadf10c57759cfb242638da25715fb14b55f895c6b11f10b333f4877985` | YES |
| `docs/architecture/16_P4_GOVERNANCE_CORE_CONTRACT.md` | `79c3b4c1c2574f07464486f5480d970614615ea86be64fc2bb136082d0f7f6d4` | `79c3b4c1c2574f07464486f5480d970614615ea86be64fc2bb136082d0f7f6d4` | YES |
| `docs/architecture/RELATED_SEMANTIC_PROJECTION_CONTRACT.md` | `7acac3245e9694d9761d43e3e6f3240b16e39a0883f52287abad928b96056768` | `7acac3245e9694d9761d43e3e6f3240b16e39a0883f52287abad928b96056768` | YES |
| `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md` | `d4b8facacab78748e76dd6a26a8e159e72aa8b33209e5c035acb7c1e1f8635cb` | `d4b8facacab78748e76dd6a26a8e159e72aa8b33209e5c035acb7c1e1f8635cb` | YES |

### Staging capability parity

```text
RUNTIME_REGISTERED=58
TOOLS_LIST_EXPOSED=58
CALLABLE_DISPATCHED=58
EASY_MCP_DESCRIPTOR_EXPOSED=46
CONNECTOR_DISCOVERABLE=46
```

The 12 connector gaps are exposure gaps, not runtime capability absence. The
five capability states remain separate. The live evidence does not establish
successful invocation of every registered operation.

### Live golden-case summary

```text
ARTICLE_55=PASS; accepted=true; MEDIA_COMPLETE; blockers=NONE
ARTICLE_575=PASS; accepted=true; MEDIA_COMPLETE; blockers=NONE
ARTICLE_548=PASS; accepted=true; MEDIA_PLACEHOLDER; blockers=NONE; upload_required=false
VIDEO_GOLDEN=PASS; canonical live readback succeeded
KNOWLEDGE_GOLDEN=PASS; supporting Source/Evidence present on canonical readback
MEDIA_GOLDEN=PASS; canonical live readback succeeded; role vocabulary preserved
PUBLIC_IDENTITY_SAMPLE=PASS; scoped audit KEEP=1 CHANGE=0 BLOCKED=0
```

The single Public Identity sample does not prove global coverage. No Article,
Media, Video, Knowledge, Graph, URL or Public Identity mutation was performed.

## Final program closeout decision

```text
PROGRAM_STATUS=ARCHITECTURAL_PROGRAM_COMPLETE_PENDING_LIVE_MUTATION_OR_DEPLOYMENT_ACCEPTANCE
GATE_8_READ_ONLY=PASS
CONTRACT_DOCUMENTATION_PARITY=PASS
SOURCE_REVISION_RECONCILIATION=PASS_DOCUMENTATION_ONLY_DELTA
FULL_DEPLOYMENT_PARITY=NOT_PROVEN
RELEASE_CUTOVER=NOT_AUTHORIZED
```

The architectural/documentation program may close at this level. Deployment,
live canonical-data mutation, Public Identity rollout and connector acceptance
remain separate release/cutover work and are not implied by this closeout.
```
