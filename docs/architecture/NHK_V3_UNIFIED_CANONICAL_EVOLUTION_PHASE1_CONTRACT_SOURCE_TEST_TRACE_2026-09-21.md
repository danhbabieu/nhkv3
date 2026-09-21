# NHK V3 Unified Canonical Evolution Phase 1 Contract Source Test Trace

## Scope and authority

This is Phase 1 repository/source evidence only. It does not amend doctrine,
choose `KEEP`, `REFRAME`, `CONSOLIDATE`, `DEPRECATE` or `CONFLICT`, and it is
not a Master Plan or a competing authority. The Constitution and ACTIVE
contracts remain normative; executable source is capability truth; tests are
behavior evidence; this report records traceability and questions for the
architectural/documentation review.

Source revision audited: `340c7493d06135d589b3f8e61310ac40080981f1`.

## ACTIVE contract inventory

All 49 ACTIVE registry entries were inventoried. The domain trace below groups
them without changing their individual authority or status.

| Group | ACTIVE documents |
|---|---|
| Constitution/operator | `AGENTS.md`; `docs/constitution/READ_FIRST.md`; `docs/constitution/NHK_V3_CONSTITUTION.md`; `CURRENT_DOCUMENTATION_STATUS_INDEX.md`; `V3_EXECUTION_STATE.md`; `V2_V3_PARITY_MATRIX.md`; `V3_BRAND_RELATIONSHIP_MATRIX.md`; `V3_FRONTEND_ROUTE_INVENTORY.md` |
| Authority/public identity | `02_AUTHORITY_BOUNDARY.md`; `13_AUTHORITY_CORE_CONTRACT.md`; `21_P5_CANONICAL_DOMAIN_FOUNDATION.md`; `ENTITY_PROFILE_CLOCK_TYPE_CONTRACT.md`; `CLOCK_TYPE_ECOSYSTEM_CONTRACT.md`; `PUBLIC_BRAND_NAMING_CONTRACT.md`; `PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md`; `V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md`; `V3_PUBLIC_ROUTE_AUDIT.md` |
| Article | `ARTICLE_INGEST_CONTRACT.md`; `ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md`; `seo/ARTICLE_SEO_PROJECTION_CONTRACT.md` |
| Media | `04_MEDIA_MODEL.md`; `VISUAL_SUPPORT_REQUIREMENT_CONTRACT.md`; `22_P6_MEDIA_VIDEO_FOUNDATION.md`; `ADMIN_MEDIA_INPUT_GUIDANCE.md` |
| Video | `VIDEO_SEMANTIC_INGEST_CONTRACT.md`; `VIDEO_RELATIONSHIP_CONTRACT.md`; `VIDEO_HUB_CLASSIFICATION_CONTRACT.md`; `VIDEO_YOUTUBE_SOURCE_CONTRACT.md`; `seo/VIDEO_SEO_PROJECTION_CONTRACT.md`; `mcp/MCP_V3_VIDEO_WORKFLOW.md` |
| Knowledge | `06_KNOWLEDGE_SOURCE_MODEL.md`; `DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`; `GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`; `COLLECTOR_PROFILE_CONTRACT.md` |
| Graph | `11_GRAPH_CORE_CONTRACT.md`; `RELATED_SEMANTIC_PROJECTION_CONTRACT.md` |
| Governance/compliance | `16_P4_GOVERNANCE_CORE_CONTRACT.md`; `18_GOVERNANCE_FAILURE_AND_RETRY.md`; `PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md` |
| MCP/control plane | `MCP_V3_CONTENT_OPERATIONS.md`; `NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md` |
| Deployment/recovery | `P0_DEPLOYMENT_PREFLIGHT.md`; `V3_SNAPSHOT_RECOVERY_RUNTIME.md` |
| SEO/presentation | `SHARED_FEED_ORDERING_CONTRACT.md`; `seo/ENTITY_SEO_PROJECTION_CONTRACT.md`; `seo/MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md`; `seo/NHK_V3_SEO_CORE_CONTRACT.md`; `seo/PUBLIC_URL_SLUG_CONTRACT.md`; `seo/SITEMAP_INDEXABILITY_CONTRACT.md` |

`MCP_V3_ABILITY_EXPOSURE.md` is classified HISTORICAL by the registry and was
not used as current capability authority.

## Contract to source to test matrix

| Domain | Contract sections/traced rules | Source implementation and persistence | MCP/Ability/action surface | Test protection | Trace result |
|---|---|---|---|---|---|
| Capture | `ARTICLE_INGEST_CONTRACT` §§32–50, 114–152, 240–431; Control Plane §§163–309; MCP Content Operations §§36–85, 254–339 | `Domain/Capture/{CaptureRecord,ContentIntent,CaptureStage}`; `Application/Capture/EditorialCaptureCoordinator`, `GovernedCaptureContinuationService`, `ContentIntentRouter`, `EditorialCaptureContinuationService`, `RelationProposalReconciliationService`; `Infrastructure/Capture/WpdbCaptureRepository`, `WpdbCaptureAddendumRepository`; migrations 017–018; tables `nhk_editorial_captures`, `nhk_editorial_capture_addenda` | `nhk.capture.ingest`, `nhk.capture.get`; downstream Article/Media/Video/Knowledge and proposal handlers | `EditorialCaptureContinuationTest`, `EditorialCaptureConvergenceE2ETest`, `GovernedCaptureContinuationServiceTest`, `ContentIntentRouterTest`, `CaptureCurrentOutcomeReducerTest`, `CapturePhaseReceiptReducerTest`, `McpCaptureReadContractTest`, `CaptureCanonicalReadbackIntegrationTest` | Implemented code-side. Orchestration and domain policy are adjacent in `EditorialCaptureCoordinator`/`GovernedCaptureContinuationService`; architectural ownership question remains open. |
| Governance | `16_P4_GOVERNANCE_CORE_CONTRACT` §§17–34, 80–97; `18_GOVERNANCE_FAILURE_AND_RETRY` §§8–50; Control Plane §§237–265 | `Domain/Governance/{Proposal,ProposalState,DependencyGraph,CommandCanonicalizer}`; `Application/Governance/{GovernanceService,ControlledApplyService,ProposalEligibilityService,CanonicalApplyReadBackVerifier,GovernedSemanticIngestOrchestrator}`; `Infrastructure/Governance/WpdbProposalRepository`, `WpdbApplyAttemptRepository`, `WpdbDependencyRepository`, `WpdbAuditSink`; migrations 003, 020; tables proposals/approvals/dependencies/apply attempts/audit | `nhk.proposal.create/submit/review/approve/reject/eligibility/apply`; governed ingest handlers | `GovernanceCoreTest`, `GovernanceApplyContractTest`, `GovernanceQueueActionServiceTest`, `GovernedSemanticIngestOrchestratorTest`, `P4ControlledApplyIntegrationTest`, `P4GovernanceAcceptanceIntegrationTest`, `McpProposalLifecycleExposureTest` | Strong trace. Two-connection locking/idempotent concurrency is explicitly still an acceptance requirement in `18_GOVERNANCE_FAILURE_AND_RETRY.md:22`; local unit evidence cannot close that runtime gap. |
| Article | `ARTICLE_INGEST_CONTRACT` §§13–20, 50–142; `ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT` §§13–135; `ARTICLE_SEO_PROJECTION_CONTRACT` §§7–27 | `Domain/Article`; `Application/Article/{ArticleIngestCoordinator,ArticleIngestPreflight,ArticlePublicationGate,OwnerPublicationApplicationService,ArticleResearchPreflight,ArticleSeoGate,ArticleVerificationReader}`; `Application/Semantic/ArticleComposer`; `Infrastructure/Article/{WpEditorialStateReader,WpdbArticleOperationReceiptRepository,WpdbOwnerPublicationDecisionRepository}`; native `wp_posts`; migrations 010–011 and `nhk_article_operations`, `nhk_article_media_blueprints` | `nhk.article.preflight`, `nhk.article.ingest`, draft/publication continuation tools and internal Ability bridge | `ArticleIngestCoordinatorTest`, `ArticleIngestPreflightTest`, `ArticlePublicationGateTest`, `ArticleSeoGateTest`, `ArticleResearchPreflightTest`, `ArticleComposerTest`, `McpArticleContractTest`, `ArticleIngestPost55ReconciliationIntegrationTest` | Article contract is implemented as a cross-owner orchestration boundary. The SEO contract explicitly reports CODE_GAP for a complete planner/projection; see issue register. |
| Media | `04_MEDIA_MODEL` §§8–82, 147–231, 299–402; `VISUAL_SUPPORT_REQUIREMENT_CONTRACT` §§20–99; P6 §§43–145; `ADMIN_MEDIA_INPUT_GUIDANCE` | `Domain/Media/{Media,MediaAsset,MediaUsage,VisualSupportRequirement,registries}`; `Application/Media/{MediaService,MediaUsageReconciler,MediaBindingService,MediaBatchUploadService,ImageIngestEntrypoint,MediaPreBindingReadiness}`; `Infrastructure/Media/WpdbMediaRepository`, asset/usage/binding/attachment repositories; migrations 004, 008, 011, 012, 019, 021, 022; tables `nhk_media`, assets/usages/attachments/bindings/visual support | `nhk.media.get/update/binding.get/bind/usage/upload-batch/widget-upload/upload-widget.open/ingest/attachment.get` | `MediaServiceUsageIdentityTest`, `MediaUsageReconcilerTest`, `MediaBindingServiceTest`, `MediaCanonicalDeliveryTest`, `MediaAssetDeliveryTest`, `ImageIngestEntrypointTest`, `ArticleMediaPolicyTest`, `MediaRuntimeDependencyClosureTest`, `WordPressMediaIngestIntegrationTest` | Media/MediaAsset/MediaUsage boundaries are visible and tested. MediaUsage role, suitability and semantic predicate remain distributed across Media, Article, Graph and SEO contracts; policy-owner audit required. |
| Video | `VIDEO_SEMANTIC_INGEST_CONTRACT` §§20–294; `VIDEO_RELATIONSHIP_CONTRACT` §§35–69; Hub/YouTube/source/SEO contracts; `MCP_V3_VIDEO_WORKFLOW` | `Domain/Video/{Video,YouTubeVideoIdentity,YouTubeSourceSnapshot,VideoCompletenessResult}`; `Application/Video` intake/refresh/reconciliation services; `Infrastructure/Video/WpdbVideoRepository`; migration 004, table `nhk_videos` | `nhk.video.ingest`, `nhk.video.source.refresh`, `nhk.video.get`; Capture Video child flow; governed proposal lifecycle | `VideoSemanticCoreTest`, `VideoCompletenessPersistenceTest`, `VideoSourceRefreshCommandTest`, `VideoProposalReconciliationServiceTest`, `VideoEditorialResumePlannerTest`, `VideoRelationLifecycleTest`, `CaptureVideoProvenancePlannerTest`, `HistoricalVideoRelationEvidenceReconciliationTest`, `P6MigrationIntegrationTest` | Strong code/test trace. Source refresh, semantic attachment and public projection are separately owned; full live mutation acceptance remains outside this audit. |
| Knowledge | `06_KNOWLEDGE_SOURCE_MODEL` §§24–181; `GOVERNED_LIVING_KNOWLEDGE_DESIGN` §§14–272; Dictionary §§1–20; Collector Profile | `Domain/Knowledge/{KnowledgeClaim,Source,Evidence,KnowledgeFacetProfile}`; `Application/Knowledge` and enrichment/planning services; `Infrastructure/Knowledge/{WpdbKnowledgeRepository,WpdbSourceRepository,WpdbEvidenceRepository}`; migrations 005, 007; tables claims/sources/evidence; Dictionary migration 015; projection migration 016 | `nhk.knowledge.get/ingest`, `nhk.source.get/ingest`, `nhk.evidence.get/ingest`; Capture Knowledge Delta | `KnowledgeEnrichmentPlannerTest`, `ClaimRetrievalScopeTest`, `ClaimReusePolicyTest`, `KnowledgeFragmentProjectionTest`, `GovernedLivingKnowledgeE2ETest`, `P7KnowledgeIntegrationTest`, `McpReadContractTest`, `GovernedSemanticIngestIntegrationTest` | Canonical claim/source/evidence ownership is implemented. Scope/provenance and lexical Dictionary ownership are split across multiple contracts; inheritance/contradiction policy is not a single executable matrix. |
| Authority | `02_AUTHORITY_BOUNDARY`; `13_AUTHORITY_CORE_CONTRACT`; P5; Clock Type, naming, dossier contracts | `Domain/Authority/{AuthorityEntity,EntityTypeRegistry,CanonicalEntityTypeCatalog}`; `Application/Authority/{AuthorityService,AuthorityIntentPlanner}`; `Infrastructure/Authority/WpdbAuthorityRepository`; migration 002, table `nhk_entities` | `nhk.entity.get`, `nhk.semantic.resolve`, `nhk.entity.neighborhood`; Authority Capture planning/apply | `AuthorityCoreTest`, `AuthorityHydrationTest`, `AuthorityIntentPlannerTest`, `AuthorityPlanFingerprintTest`, `AuthorityStagingAdmissionTest`, `P5CanonicalDomainIntegrationTest`, `ConversationalAuthority*Test` | Registry and lifecycle trace is strong. Clock Type and public dossier are profiles/projections over Authority, but contract overlap creates review questions about policy ownership. |
| Graph | `11_GRAPH_CORE_CONTRACT` §§23–190; `RELATED_SEMANTIC_PROJECTION_CONTRACT` §§1–329; Brand relationship matrix | `Domain/Graph/{GraphEdge,GraphNode,EndpointTypeRegistry,PredicateRegistry}`; `Application/Graph/{GraphService,RelatedEntityQuery,GraphInventoryService}`; `Infrastructure/Graph/{WpdbGraphRepository,CoreEndpointResolverRegistrar}`; migration 001, tables graph nodes/edges/predicates | `nhk.graph.inventory`, `nhk.entity.neighborhood`, `nhk.relation.backfill.dry_run/apply`; relation creation through governed proposal | `GraphCoreContractTest`, `GraphInventoryServiceTest`, `GraphDistributionAuditTest`, `AuthorityGraphIntegrationTest`, `GraphWpdbIntegrationTest`, `RelatedEntityQueryTest` | Direct Graph mutation boundary is clear. Related projection contract reports runtime/policy gaps; derived relation and ranking policy are not fully executable across all registered profiles. |
| SEO | SEO Core; Article/Entity/Media Image/Video SEO; Sitemap; Shared Feed Ordering | `Application/Seo/{PublicSeoProjection,EntitySeoProjection,VideoSeoProjection}`; Article/Media SEO projection services; sitemap providers; theme metadata hooks; no independent semantic table | Indirectly `nhk.article.preflight/ingest`, search, public URL audit, public routes; no standalone SEO writer | `SeoDocumentationContractTest`, `ArticleSeoGateTest`, `EntitySeoProjectionTest`, `VideoSeoProjectionTest`, `PreferredImageSeoProjectionTest`, `LivingKnowledgeSeoStabilityGuardTest`, frontend/route tests | Projection ownership is present, but Article SEO blueprint/planner and live sitemap/image integration are explicitly partial in contract evidence. |
| Public Identity | `PUBLIC_URL_SLUG_CONTRACT`; `V3_PUBLIC_ENTITY_IDENTITY_MATRIX`; `V3_PUBLIC_ROUTE_AUDIT`; dossier/SEO consumers | `Application/PublicIdentity/{PublicIdentityService,PublicRouteResolver,HistoricPublicRouteResolver}`; `Infrastructure/PublicIdentity/{WpdbPublicIdentityRepository,WordPressPublicUrlMaintenanceRuntime,WpdbHistoricPublicRouteResolver}`; migration 014, tables identities/history | `nhk.public-url.audit`, internal `nhk.public-url.reproject`; route resolvers/theme/REST/MCP readers | `PublicIdentityServiceTest`, `PersistedPublicIdentityRouteTest`, `PublicUrlMaintenanceServiceTest`, `HistoricPublicRouteResolverTest`, `PublicEntityRoutesTest`, `McpPublicUrlMaintenanceContractTest`, `PublicSlugMigrationIntegrationTest` | Route/identity policy is implemented code-side with strong collision/one-hop tests. Runtime activation and full route parity remain deployment/runtime evidence, not source absence. |

## Issue register

Severity uses `P0` for constitutional or data-owner risk, `P1` for cross-domain
contract/runtime risk, and `P2` for consolidation or completeness risk.

### No confirmed CONSTITUTION_CONFLICT

The static repository audit found no confirmed source behavior that overrides a
Constitution rule. Several contracts explicitly label forbidden compatibility
behavior as `CONSTITUTION_CONFLICT`, for example `11_GRAPH_CORE_CONTRACT.md:70`,
`06_KNOWLEDGE_SOURCE_MODEL.md:21`, and
`V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md:108`; those are guardrails and audit
categories, not proof that the current source violates them.

### CONTRACT_CONFLICT candidates

| ID/severity | Evidence | Observed behavior | Affected domains | Architectural question |
|---|---|---|---|---|
| CCF-01 / P1 | `ARTICLE_INGEST_CONTRACT.md:32–50`; `MCP_V3_CONTENT_OPERATIONS.md:36–85`; `NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md:78–112` | Three documents prescribe the canonical Capture entry point and direct-writer restrictions, while direct Article/Media/Video/Knowledge tools remain cataloged as internal compatibility boundaries. The text is probably intentionally layered, but policy ownership is not mechanically singular. | Capture, Article, Media, Video, Knowledge, MCP | Which contract is the single normative owner of entry-point/admission policy, and which documents are adapters or operational projections? |
| CCF-02 / P1 | `04_MEDIA_MODEL.md:147–231,402`; P6 §§23–43; MCP Content Operations §§693–876; Article Ingest §§185–239 | Media reconciliation, representative selection, suitability and post-ingest reconciliation are described in several active contracts. No single executable registry proves that precedence is identical across every caller. | Media, Article, Graph, Knowledge, MCP | Which contract owns suitability/representative precedence, and what is the required cross-contract conformance test? |

### IMPLEMENTATION_DRIFT candidates

| ID/severity | Evidence | Observed behavior | Affected domains | Architectural question |
|---|---|---|---|---|
| IDR-01 / P1 | `NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md:495–510`; `MCP_V3_CONTENT_OPERATIONS.md:534–548`; source `McpToolCatalog`, `McpCapabilityManifest`, `McpAbilityRegistration` | The contracts describe partial/CODE_GAP capability-manifest, related-query, SEO blueprint and Article research surfaces, while source contains catalog, transport, ability and several read/planning classes. This may be intentional partial implementation, but source-vs-contract status is not generated from one status registry. | MCP, Article, Graph, SEO | Which implementation status source is authoritative, and how is “class exists” distinguished from “complete callable capability”? |
| IDR-02 / P1 | `V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md:99`; `V3_PUBLIC_ROUTE_AUDIT.md:59,83`; source Public Identity service/migration 014 | Source contains persisted Public Identity implementation and tests, while active evidence still describes runtime activation as unverified/pending. | Public Identity, SEO, deployment | Which target-runtime read-back closes activation, and how are dated “pending” statements superseded without deleting audit history? |

### DOCUMENTATION_DRIFT

| ID/severity | Evidence | Observed behavior | Affected domains | Architectural question |
|---|---|---|---|---|
| DOC-01 / P1 | Phase 0 baseline `Gate 0 reconciliation addendum` versus its later final closeout; `V3_EXECUTION_STATE.md` dated checkpoints | Phase 0 evidence contains superseded conditional status alongside final PASS evidence. Dated execution state also contains older runtime/count conclusions. | Program governance, all domains | What is the formal supersession convention for evidence paragraphs and which document is the current status projection? |
| DOC-02 / P2 | `MCP_V3_CONTENT_OPERATIONS.md:522–525` versus current source catalog and staging bootstrap | The contract correctly warns that historical counts are obsolete, but capability status remains partly hand-maintained. | MCP | Should the active capability table be generated/verified from catalog metadata, or remain manually curated evidence? |

### DUPLICATE_POLICY_OWNER candidates

| ID/severity | Evidence | Observed behavior | Affected domains | Architectural question |
|---|---|---|---|---|
| DPO-01 / P1 | `EditorialCaptureCoordinator.php`, `GovernedCaptureContinuationService.php`, `McpTransport.php`; Article/MCP Control Plane completion sections | Coordinator, continuation service and MCP transport each participate in admission, continuation/completion diagnostics. They are intended as orchestration/adapter layers, but the policy boundary is not obvious from naming alone. | Capture, MCP, Governance | Which class is allowed to decide semantic completion, and which may only reduce/transport an owner result? |
| DPO-02 / P1 | `16_P4_GOVERNANCE_CORE_CONTRACT.md:80–97`; `18_GOVERNANCE_FAILURE_AND_RETRY.md:8–50`; `McpGovernanceHandler`; `ControlledApplyService` | Governance policy is expressed in contract, MCP handler and apply service. Current tests cover behavior, but no source-level assertion prevents a handler from becoming a second policy owner. | Governance, MCP | What architectural test enforces that MCP/Admin remain adapters and Controlled Apply remains the sole mutation owner? |

### GOD_SERVICE_RISK

| ID/severity | Evidence | Observed behavior | Affected domains | Architectural question |
|---|---|---|---|---|
| GSR-01 / P1 | `EditorialCaptureCoordinator.php:23`, `GovernedCaptureContinuationService.php:28`, `Plugin.php` composition root | These classes touch multiple owner branches, diagnostics, staging admission and read-back composition. This is an observed coupling risk, not proof of a violation. | Capture, Article, Media, Video, Knowledge, Governance | Which dependencies are orchestration-only, and what policy must move behind owner-specific services before Phase 4? |
| GSR-02 / P2 | `McpTransport.php:19`, `McpAbilityRegistration.php`, `Plugin.php` | Transport, catalog, Ability and capability mapping are spread across multiple adapters with parity tests but no single generated contract artifact. | MCP, all domains | Is the split an intentional adapter architecture, or should a generated capability manifest become the one projection source? |

### UNDER_SPECIFIED_CONTRACT

| ID/severity | Evidence | Observed behavior | Affected domains | Architectural question |
|---|---|---|---|---|
| USC-01 / P1 | Article/Knowledge/Graph contracts; source `ClaimRetrievalScope`, `RelatedEntityQuery`, `ArticleComposer` | Scope, provenance, contradiction, relation path and editorial selection are each specified, but no single cross-domain matrix defines inheritance, narrowing/widening and conflict precedence. | Knowledge, Graph, Article, Video | What is the canonical scope lattice and how does it constrain reuse/generalization? |
| USC-02 / P1 | Governance contracts and `GovernedCaptureContinuationService` | Execution refresh, material semantic change, dependency freshness and approval validity are individually described but not one executable decision table. | Governance, Capture, all writers | What exact fields constitute semantic binding versus refreshable execution state? |
| USC-03 / P2 | `RELATED_SEMANTIC_PROJECTION_CONTRACT.md:245–277` | Runtime gap report identifies missing traversal/ranking/projection capabilities without a single acceptance status per registered profile. | Graph, Authority, public projections | Which registered endpoint/profile combinations are contract-complete now? |

### CONTRACT_WITHOUT_IMPLEMENTATION

| ID/severity | Evidence | Observed behavior | Affected domains | Architectural question |
|---|---|---|---|---|
| CWI-01 / P1 | `MCP_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md:499–503` | Full SEO Blueprint planner/projection is explicitly CODE_GAP; source has blueprint/SEO components but not the complete contract surface. | Article, SEO | What minimum complete SEO planner is required before the contract can be marked implemented? |
| CWI-02 / P1 | `RELATED_SEMANTIC_PROJECTION_CONTRACT.md:245–277` | The contract requires bounded direct/derived query behavior, while its own runtime gap report records absent or partial traversal/policy support. | Graph, Authority, public UI | Which gaps are required for contract conformance versus deferred enhancements? |
| CWI-03 / P1 | `V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md:69,99`; P5 Product/Specimen sections | Product–Specimen relation remains registry/contract gap; persisted Public Identity activation remains runtime-gated. | Authority, Graph, Public Identity | Which missing relation/activation is intentionally deferred and which is required for current public behavior? |

### SOURCE_WITHOUT_CONTRACT candidates

| ID/severity | Evidence | Observed behavior | Affected domains | Architectural question |
|---|---|---|---|---|
| SWC-01 / P2 | Source `McpToolCatalog` includes `nhk.capture.get`, `nhk.media.update`, `nhk.video.source.refresh`, and `nhk.public-url.audit/reproject`; these are spread across owner contracts and MCP docs rather than one per-operation contract index. | No unowned semantic vocabulary was proven; traceability is distributed and difficult to audit mechanically. | MCP, Capture, Media, Video, Public Identity | Should every executable operation have a single contract anchor and status entry? |
| SWC-02 / P2 | `Application/Semantic/ArticleComposer.php`, managed-section services, and projection services | Source-side composition/projection primitives span Article, SEO and public projection documents; ownership is documented but not mechanically indexed. | Article, SEO, public projection | Is there a canonical composition/projection contract index required before Phase 2? |

### HISTORICAL_OR_DEAD_RULES

| ID/severity | Evidence | Observed behavior | Architectural question |
|---|---|---|---|
| HDR-01 / P2 | Registry classifies `docs/mcp/MCP_V3_ABILITY_EXPOSURE.md` as HISTORICAL; current `READ_FIRST.md` explicitly rejects it as current capability truth | The file remains discoverable but is not current law. | Should historical MCP exposure material be retained in the active manifest as historical, or moved to a dated evidence archive? |
| HDR-02 / P2 | `V3_EXECUTION_STATE.md` and P6/MCP dated checkpoints | Older counts and runtime conclusions coexist with newer checkpoints. | What supersession marker makes historical evidence machine-filterable without deleting it? |

## Cross-domain findings

1. The canonical owner boundaries are generally visible in source and tests.
2. The largest Phase 1 issue is not missing domain classes; it is distributed
   policy ownership across contracts, orchestration, MCP adapters and public
   projection layers.
3. Runtime readiness and implementation completeness are repeatedly distinct,
   but the distinction is mostly prose/status tables rather than one generated
   conformance model.
4. Capture is the highest coupling surface and should be treated as an
   orchestration-risk audit, not automatically as a new domain owner.
5. Public Identity and SEO have strong source/test seams, but deployment/runtime
   activation evidence remains separate from source implementation evidence.
6. No confirmed Constitution conflict was found in this static audit.

## Architectural decisions required from review

The following are questions, not decisions made by Codex:

- Which active document owns each cross-domain policy, and which documents are
  explanatory projections?
- What is the executable materiality/approval/refresh decision table?
- What is the canonical scope/provenance/generalization matrix?
- What conformance artifact proves catalog, Ability, MCP and contract parity?
- Which CODE_GAP/REGISTRY_GAP items are intentionally deferred versus required
  for current contract validity?
- What source-level rule prevents Capture, MCP or Governance adapters from
  becoming duplicate policy owners?

## Audit limitations

- Static source/test audit only; no production behavior was changed.
- Local WordPress/MariaDB runtime remained unavailable.
- No MCP mutation callability was exercised.
- Staging read evidence is referenced from Phase 0 evidence; it is not repeated
  here as new live verification.
- This report does not choose a contract disposition.
