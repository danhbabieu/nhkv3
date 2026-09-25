<?php
declare(strict_types=1);
namespace NHK\Core;
use NHK\Core\Shared\Health\HealthCheck;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Infrastructure\Migration\GraphMigration001;
use NHK\Core\Infrastructure\Migration\AuthorityMigration002;
use NHK\Core\Infrastructure\Migration\GovernanceMigration003;
use NHK\Core\Infrastructure\Migration\MediaMigration004;
use NHK\Core\Infrastructure\Migration\KnowledgeMigration005;
use NHK\Core\Infrastructure\Migration\MigrationLedger006;
use NHK\Core\Infrastructure\Migration\KnowledgeEvidenceMetadataMigration007;
use NHK\Core\Infrastructure\Migration\MediaAssetMetadataMigration008;
use NHK\Core\Infrastructure\Migration\ProjectionContextMigration009;
use NHK\Core\Infrastructure\Migration\ArticleIngestMigration010;
use NHK\Core\Infrastructure\Migration\ArticleMediaMigration011;
use NHK\Core\Infrastructure\Migration\MediaWordPressBridgeMigration012;
use NHK\Core\Infrastructure\Migration\OwnerPublicationDecisionMigration013;
use NHK\Core\Infrastructure\Migration\PublicIdentityMigration014;
use NHK\Core\Infrastructure\Migration\DictionaryMigration015;
use NHK\Core\Infrastructure\Migration\ClaimProjectionMigration016;
use NHK\Core\Infrastructure\Migration\{EditorialCaptureAddendumMigration018, EditorialCaptureMigration017, GovernanceSubjectBindingMigration020, MediaBindingOperationMigration022, MediaUsageMetadataMigration021, VisualSupportRequirementMigration019};
use NHK\Core\Infrastructure\Migration\MigrationDatabaseGuard;
use NHK\Core\Application\Governance\GovernanceCapabilities;
use NHK\Core\Application\Governance\{AuthorityStagingAdmission, CaptureChildRelationStagingAdmission, CaptureDependencyStagingAdmission, MediaBindingStagingAdmission, MediaMetadataStagingAdmission, VideoStagingAdmission};
use NHK\Core\Application\Runtime\SemanticWritePolicyResolver;
use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpArticleIngestHandler, McpGovernanceHandler, McpReadHandler, McpSemanticContextResolver, McpToolCatalog, McpTransport, McpDocumentationRegistry};
use NHK\Core\Application\Media\{ImageIngestEntrypoint, MediaBatchUploadService, MediaBindingService};
use NHK\Core\Application\Capture\{CaptureArticlePreflightHandoff, CaptureEditorialWriteGuard, CapturePhaseReceiptReducer, CaptureVideoProvenancePlanner, CaptureVideoPublicationVerifier, ClockTypeShadowClassifier, ContentPreparationOrchestrator, EditorialCaptureContinuationService, EditorialCaptureCoordinator, GovernedCaptureContinuationService, RelationProposalReconciliationService};
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, ClaimReusePolicy, EditorialClaimRetrievalService, EditorialKnowledgeSelector, EditorialQualityGate, KnowledgeWriterPreviewService, ReaderJourneyPlanner, SharedEditorialComposer, SharedEnrichmentBoundary, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Application\Article\{ArticleEditorialAdapter, ArticleIngestCoordinator, ArticleIngestPreflight, ArticleResearchPreflight, ArticleVerificationReader, SemanticProposalPlanner, OwnerPublicationApplicationService};
use NHK\Core\Infrastructure\Http\ReadApi;
use NHK\Core\Infrastructure\Http\AdminWorkbenchReadApi;
use NHK\Core\Infrastructure\Http\AdminMediaUsageApi;
use NHK\Core\Infrastructure\Http\GovernanceApi;
use NHK\Core\Infrastructure\Http\VideoRelationAdminApi;
use NHK\Core\Infrastructure\Http\SearchApi;
use NHK\Core\Infrastructure\Http\EntityApi;
use NHK\Core\Infrastructure\Http\GraphApi;
use NHK\Core\Infrastructure\Http\PublicMediaVideoRoutes;
use NHK\Core\Infrastructure\Http\PublicMediaAssetRoutes;
use NHK\Core\Infrastructure\Http\PublicEntityRoutes;
use NHK\Core\Infrastructure\Http\PublicComparisonRoutes;
use NHK\Core\Infrastructure\Http\PublicEditorialRoutes;
use NHK\Core\Infrastructure\Http\LegacyUrlRedirects;
use NHK\Core\Infrastructure\Http\PublicKnowledgeRoutes;
use NHK\Core\Infrastructure\Http\PublicVideoSitemapRoutes;
use NHK\Core\Infrastructure\Http\McpApi;
use NHK\Core\Infrastructure\Mcp\ChatGptMcpGateway;
use NHK\Core\Infrastructure\Mcp\EasyMcpNativeFileCompatibilityAdapter;
use NHK\Core\Infrastructure\Admin\AdminPage;
use NHK\Core\Infrastructure\Admin\AdminShell;
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaBindingOperationRepository, WpdbMediaRepository, WpdbMediaUsageRepository, WordPressImageSitemapProvider, WordPressMediaAttachmentBridge, WordPressMediaAttachmentIngestor, WordPressMediaAttachmentWriteGuard};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Infrastructure\PublicIdentity\WpdbPublicIdentityRepository;
use NHK\Core\Application\PublicIdentity\HistoricPublicRouteService;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Article\{WpEditorialStateReader, WpdbArticleOperationReceiptRepository, WpdbOwnerPublicationDecisionRepository};
use NHK\Core\Contracts\Article\PublicationPrincipal;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Audit\WpdbClockTypeClassificationAuditFactory;
use NHK\Core\Application\Graph\{BrandAggregationQuery, GraphService, PredicateTraversalPolicy, RelatedSemanticQuery, SemanticNeighborhoodQuery, StructuralContextQuery, RelationshipReadService};
use NHK\Core\Application\Graph\{LegacyRelationPlanner, RelationBackfillCandidate, RelationBackfillService};
use NHK\Core\Application\Inventory\{CanonicalInventoryService, GraphInventoryService};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, GraphClockTypeCanonicalMembershipReader, WpdbAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Governance\WpdbDependencyRepository;
use NHK\Core\Infrastructure\Governance\GovernanceRuntimeFactory;
use NHK\Core\Application\Entity\{ComparisonPageQuery, EntityMediaProjection, EntityPageQuery, EntityProfileAdminProjection, PublicEndpointEligibilityResolver, PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver, RelatedContentQuery};
use NHK\Core\Application\Media\{ArticleMediaCoordinator, ArticleMediaSeoProjection, MediaEnrichmentFinalReadbackPolicy, MediaIngestGateway, MediaService, MediaVideoPageQuery, PublicMediaGalleryQuery, VisualOpportunityDetector, VisualSupportRequirementService};
use NHK\Core\Application\Video\{VideoCompletenessPolicy, VideoEditorialAdapter, VideoEditorialGenerator, VideoHubClassifier, VideoIntakeService, VideoInternalSemanticResearcher, VideoKnowledgeEnrichmentPlanner, VideoRelationCandidatePlanner, VideoSeoProjection, VideoService, VideoSourceRefreshCommand, YouTubeDataApiClient, YouTubeSourceAdapter};
use NHK\Core\Application\Home\HomeSemanticQuery;
use NHK\Core\Application\Search\SearchSemanticQuery;
use NHK\Core\Application\Knowledge\{EntityKnowledgeProjection, KnowledgePageQuery};
use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Collector\{CollectorFacetMaintenanceExecutor, CollectorFacetMaintenanceService};
use NHK\Core\Application\WordPress\{CategoryGateway, EditorialDraftGateway};
use NHK\Core\Infrastructure\WordPress\{WpCategoryStore, WpEditorialPostStore};
use NHK\Core\Infrastructure\Capture\{WpdbCaptureAddendumRepository, WpdbCaptureRepository};
use NHK\Core\Infrastructure\Snapshot\SnapshotRuntimeComposition;

final class Plugin {
    private const REWRITE_VERSION = '10';
    public static function boot(string $pluginFile): void {
        global $wpdb;
        $captureRepository = isset($wpdb) && is_object($wpdb) ? new WpdbCaptureRepository($wpdb) : null;
        // Keep an already-installed site aware of the code's migration target;
        // activation is not required for an upgrade health check to be honest.
        update_option('nhk_core_migration_target', MediaBindingOperationMigration022::VERSION, false);
        if (self::runtimeMigrationsEnabled()) self::runPendingMigrations();
        add_action('nhk_v3_media_canonical_readback', static function (\NHK\Core\Domain\Media\Media $media, array $assets, array $contexts = []): void {
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) return;
            try {
                $mediaRepository = new WpdbMediaRepository($wpdb);
                $assetRepository = new WpdbMediaAssetRepository($wpdb);
                $usageRepository = new WpdbMediaUsageRepository($wpdb);
                $mediaService = new MediaService($mediaRepository, $assetRepository, $usageRepository);
                $dependencyIndex = new \NHK\Core\Infrastructure\Projection\WpdbProjectionDependencyIndex($wpdb);
                $projectionInvalidation = new \NHK\Core\Application\Projection\ProjectionInvalidationService($dependencyIndex, new \NHK\Core\Infrastructure\Projection\WpdbProjectionRevisionStore($wpdb));
                $reconciler = new \NHK\Core\Application\Media\VisualSupportReverseReconciliationService(
                    new \NHK\Core\Infrastructure\Media\WpdbVisualSupportRequirementRepository($wpdb),
                    new \NHK\Core\Application\Media\VisualSupportMediaSuitability(),
                    static function (string $mediaId, array $consumer) use ($mediaService): void {
                        $mediaService->addUsage($mediaId, (string) $consumer['endpoint_type'], (string) $consumer['endpoint_key'], (string) $consumer['role']);
                    },
                    static function (string $requirementId, int $revision) use ($projectionInvalidation): void {
                        $projectionInvalidation->invalidate('visual_support_requirement', $requirementId, $revision);
                    },
                );
                $reconciler->reconcile($media, $assets, $contexts !== [] ? $contexts : null);
            } catch (\Throwable $error) {
                do_action('nhk_v3_visual_support_reconciliation_failure', $media->canonicalId, $error->getMessage());
            }
        }, 20, 3);
        if ((string) get_option('nhk_core_rewrite_version', '') !== self::REWRITE_VERSION) { update_option('nhk_core_rewrite_version', self::REWRITE_VERSION, false); add_action('init', static function (): void { flush_rewrite_rules(false); }, 99); }
        // Register capabilities on every load so existing installations and
        // upgrades do not need a deactivate/activate cycle to authorize P4.
        GovernanceCapabilities::register();
        McpAbilityRegistration::reconcileEasyMcpEnabledAbilities();
        McpAbilityRegistration::reconcileEasyMcpAllowedToolPatterns();
        add_action('wp_abilities_api_categories_init', [McpAbilityRegistration::class, 'registerCategory']);
        add_filter('option_easy_mcp_ai_enabled_abilities', [McpAbilityRegistration::class, 'ensureEasyMcpEnabledAbilities']);
        // Easy MCP AI registers dynamic ability tools from its own bootstrap.
        // Initialize the WordPress ability registry before that snapshot so
        // newly-added NHK abilities are present in the bridge's tool list.
        add_action('init', [McpAbilityRegistration::class, 'bootstrapRegistry'], 1);
        // Easy MCP builds its Tool_Registry from the first REST request. Keep
        // this boundary explicit as well: a REST request may reach
        // rest_api_init without a prior request having initialized Abilities.
        // Priority 0 guarantees the registry exists before Easy MCP registers
        // its Dynamic_Tool_Registrar.
        add_action('rest_api_init', [McpAbilityRegistration::class, 'bootstrapRegistry'], 0);
        add_action('rest_api_init', [McpAbilityRegistration::class, 'logEasyMcpExportDiagnostics'], PHP_INT_MAX);
        EasyMcpNativeFileCompatibilityAdapter::register();
        ChatGptMcpGateway::register();
        add_action('wp_abilities_api_init', static function () use (&$captureRepository): void {
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) return;
            $types = new EntityTypeRegistry();
            CanonicalEntityTypeCatalog::registerInto($types);
            $authority = new WpdbAuthorityRepository($wpdb);
            $media = new WpdbMediaRepository($wpdb);
            $assets = new WpdbMediaAssetRepository($wpdb);
            $usages = new WpdbMediaUsageRepository($wpdb);
            $videos = new WpdbVideoRepository($wpdb);
            $claims = new WpdbKnowledgeRepository($wpdb);
            $sources = new WpdbSourceRepository($wpdb);
            $evidence = new WpdbEvidenceRepository($wpdb);
            $attachmentBridge = new WordPressMediaAttachmentBridge($wpdb, new MediaService($media, $assets, $usages), $media, $assets);
            $wordpressAttachments = new WordPressMediaAttachmentIngestor($attachmentBridge);
            $graphEndpoints = new EndpointTypeRegistry();
            CoreEndpointResolverRegistrar::register($graphEndpoints, $types, $authority, $media, $videos, $claims, $sources, $evidence);
            $graphRead = new GraphService(new WpdbGraphRepository($wpdb), $graphEndpoints, new PredicateRegistry(), new WpdbAuditSink());
            $neighborhood = new SemanticNeighborhoodQuery(new RelatedSemanticQuery($graphRead, new PredicateTraversalPolicy(new PredicateRegistry())));
            $graphRepository = new WpdbGraphRepository($wpdb);
            $predicates = new PredicateRegistry();
            $canonicalInventory = self::canonicalInventory($types, $authority, $media, $videos, $claims, $sources, $evidence);
            $graphInventory = new GraphInventoryService($graphRepository, $graphEndpoints, $predicates);
            $relationBackfill = self::relationBackfill($canonicalInventory, $graphInventory);
            $relationshipRead = new RelationshipReadService($graphEndpoints, $predicates, $graphRepository, null, null, [
                'media_usage' => new \NHK\Core\Application\Graph\MediaUsageRelationshipAdapter($usages),
                'evidence' => new \NHK\Core\Application\Graph\EvidenceRelationshipAdapter($evidence, $claims, $sources),
            ]);
            McpAbilityRegistration::registerDiagnosticsAbility();
            McpAbilityRegistration::registerReadAbilities(new McpReadHandler($authority, $types, $media, $assets, $usages, $videos, $claims, $evidence, new MigrationStatus(), $sources, null, new McpSemanticContextResolver($authority, $types), $wordpressAttachments, $neighborhood, $canonicalInventory, $graphInventory, $relationBackfill, new WpdbMediaBindingOperationRepository($wpdb), $captureRepository, $relationshipRead));
            McpAbilityRegistration::registerCapabilityGatedReadAbilities();
            McpAbilityRegistration::registerGovernedAbilities();
        });
        (new PublicEditorialRoutes())->register();
        LegacyUrlRedirects::register();
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) SnapshotRuntimeComposition::register($wpdb);
        if (isset($wpdb) && is_object($wpdb)) {
            $stagingAdmission = new MediaBindingStagingAdmission(new WpdbMediaRepository($wpdb), new WpdbAuthorityRepository($wpdb));
            add_filter('nhk_v3_staging_acceptance_admission', new AuthorityStagingAdmission(), 10, 5);
            add_filter('nhk_v3_staging_acceptance_admission', $stagingAdmission, 20, 5);
            add_filter('nhk_v3_staging_acceptance_admission', new MediaMetadataStagingAdmission(new WpdbMediaRepository($wpdb)), 22, 5);
            add_filter('nhk_v3_staging_acceptance_admission', new CaptureDependencyStagingAdmission(), 25, 5);
            add_filter('nhk_v3_staging_acceptance_admission', new VideoStagingAdmission(new WpdbVideoRepository($wpdb)), 30, 5);
            add_filter('nhk_v3_staging_acceptance_admission', new CaptureChildRelationStagingAdmission(), 35, 5);
        }
        $sharedAttachmentBridge = null;
        $claimOwnerUrl = static fn (\NHK\Core\Domain\Knowledge\KnowledgeClaim $claim): ?string => null;
        if (isset($wpdb) && is_object($wpdb)) {
            $publicTypes = new EntityTypeRegistry();
            CanonicalEntityTypeCatalog::registerInto($publicTypes);
            $publicAuthority = new WpdbAuthorityRepository($wpdb);
            $publicMedia = new WpdbMediaRepository($wpdb);
            $publicVideos = new WpdbVideoRepository($wpdb);
            $publicEndpoints = new EndpointTypeRegistry();
            CoreEndpointResolverRegistrar::register($publicEndpoints, $publicTypes, $publicAuthority, $publicMedia, $publicVideos);
            $publicStatus = new MigrationStatus();
            $publicGraph = new GraphService(new WpdbGraphRepository($wpdb), $publicEndpoints, new PredicateRegistry(), new WpdbAuditSink());
            $clockTypeAudit = WpdbClockTypeClassificationAuditFactory::create($publicGraph, $wpdb);
            add_filter('nhk_v3_clock_type_classification_audit', static fn (mixed $current): mixed => $current ?? $clockTypeAudit, 10, 1);
            $publicContexts = new StructuralContextQuery($publicGraph, $publicAuthority);
            $publicRoutes = new PublicRouteResolver($publicAuthority, $publicTypes, $publicContexts);
            $publicEligibility = new PublicEntityEligibilityPolicy($publicAuthority, $publicTypes, $publicRoutes, $publicContexts);
            $publicAggregation = new BrandAggregationQuery($publicGraph, $publicAuthority, $publicTypes, $publicRoutes, $publicEligibility);
            $publicAssets = new WpdbMediaAssetRepository($wpdb);
            $publicUsages = new WpdbMediaUsageRepository($wpdb);
            $publicClaims = new WpdbKnowledgeRepository($wpdb);
            $publicSources = new WpdbSourceRepository($wpdb);
            $publicEvidence = new WpdbEvidenceRepository($wpdb);
            $publicKnowledge = new EntityKnowledgeProjection($publicClaims, $publicEvidence, $publicSources, $publicStatus);
            $publicCollection = new PublicEntityCollectionQuery($publicAuthority, $publicTypes, new PublicIdentityContract($publicTypes), $publicEligibility, $publicRoutes, $publicAggregation, static fn (): bool => $publicStatus->authorityStorageReady(), new EntityMediaProjection($publicMedia, $publicAssets, $publicUsages), $publicKnowledge);
            $homeSemanticQuery = new HomeSemanticQuery($publicAuthority, $publicMedia, $publicVideos, $publicTypes, $publicStatus, $publicRoutes, $publicCollection, new PublicMediaGalleryQuery($publicMedia, $publicAssets), null, $publicClaims);
            add_filter('nhk_v3_home_semantic_modules', [$homeSemanticQuery, 'extend']);
            $claimOwnerUrl = static function (\NHK\Core\Domain\Knowledge\KnowledgeClaim $claim) use ($publicAuthority, $publicRoutes, $publicEligibility): ?string {
                $metadata = $claim->provenance['metadata'] ?? [];
                $subjectId = is_array($metadata) ? trim((string) ($metadata['subject_uuid'] ?? $metadata['subject_id'] ?? $metadata['canonical_subject_uuid'] ?? $metadata['canonical_subject_id'] ?? '')) : '';
                if ($subjectId === '') return null;
                $entity = $publicAuthority->findByCanonicalId($subjectId);
                return $entity && $publicEligibility->evaluate($entity)->eligible ? $publicRoutes->path($entity) : null;
            };
            add_filter('nhk_v3_search_semantic_results', [new SearchSemanticQuery($publicAuthority, $publicMedia, $publicVideos, $publicClaims, $publicTypes, $publicStatus, $publicRoutes, $publicCollection, $claimOwnerUrl), 'extend'], 10, 3);
            $publicRelated = new RelatedContentQuery($publicGraph, $publicAuthority, $publicMedia, $publicVideos, $publicTypes, $publicStatus, $publicEligibility);
            add_filter('nhk_v3_post_related_content', static function (array $value, int $postId) use ($publicRelated): array { return $publicRelated->forPost($postId); }, 10, 2);
            $publicEntityQuery = new EntityPageQuery($publicAuthority, $publicTypes, $publicRelated, $publicStatus, $publicRoutes, $publicCollection);
            $publicIdentityRepository = new WpdbPublicIdentityRepository($wpdb);
            \NHK\Core\Application\PublicIdentity\PublicIdentityReadRegistry::register($publicIdentityRepository);
            $historicPublicRouteService = new HistoricPublicRouteService($publicIdentityRepository);
            (new PublicEntityRoutes($publicEntityQuery, $publicTypes, $historicPublicRouteService))->register();
            (new PublicComparisonRoutes(new ComparisonPageQuery($publicEntityQuery)))->register();
            $publicMediaService = new MediaService($publicMedia, $publicAssets, $publicUsages);
            $sharedAttachmentBridge = new WordPressMediaAttachmentBridge($wpdb, $publicMediaService, $publicMedia, $publicAssets);
            $attachmentBridge = $sharedAttachmentBridge;
            $articleMedia = new ArticleMediaCoordinator($publicMediaService, $publicMedia, $publicAssets, $publicUsages, new \NHK\Core\Infrastructure\Media\WpdbArticleMediaBlueprintRepository($wpdb), null, $attachmentBridge);
            $articleSeo = new ArticleMediaSeoProjection($publicMedia, $publicAssets, $publicUsages, $attachmentBridge, null, new \NHK\Core\Infrastructure\Media\WpdbArticleMediaBlueprintRepository($wpdb));
            add_filter('nhk_v3_article_media_seo', static function (array $value, int $postId) use ($articleSeo): array { return $articleSeo->forPost((string) get_current_blog_id() . ':' . $postId); }, 10, 2);
            add_action('wp_sitemaps_init', static function (object $sitemaps) use ($articleSeo): void {
                if (isset($sitemaps->registry) && is_object($sitemaps->registry) && method_exists($sitemaps->registry, 'add_provider')) $sitemaps->registry->add_provider('images', new WordPressImageSitemapProvider($articleSeo));
            });
            $reconcilePostMedia = static function (int $postId, \WP_Post $post, bool $update) use ($articleMedia, $attachmentBridge): void {
                if (CaptureEditorialWriteGuard::active()) return;
                if ($attachmentBridge->isHandlingWrite()) return;
                if ($post->post_type !== 'post' || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) return;
                // Trash is a lifecycle-only mutation. WordPress fires
                // wp_after_insert_post for the status transition, but a
                // trashed post must never enter editorial/media enrichment.
                if ($post->post_status === 'trash') return;
                try {
                    $articleMedia->ensureForPost($postId, ['subject' => (string) $post->post_title, 'planned_title' => (string) $post->post_title]);
                } catch (\Throwable $error) {
                    do_action('nhk_v3_article_media_failure', $postId, $error->getMessage(), $update);
                }
            };
            add_action('wp_after_insert_post', $reconcilePostMedia, 20, 3);
            add_action('rest_after_insert_post', static function (\WP_Post $post, \WP_REST_Request $request, bool $creating) use ($reconcilePostMedia): void {
                $reconcilePostMedia((int) $post->ID, $post, !$creating);
            }, 20, 3);
            $adoptAttachment = static function (int $attachmentId) use ($attachmentBridge): void {
                if (WordPressMediaAttachmentWriteGuard::active()) return;
                try { $attachmentBridge->adoptAttachment($attachmentId); } catch (\Throwable $error) { do_action('nhk_v3_media_adoption_failure', $attachmentId, $error->getMessage()); }
            };
            add_action('add_attachment', $adoptAttachment, 20, 1);
            add_action('edit_attachment', $adoptAttachment, 20, 1);
            add_action('rest_after_insert_attachment', static function (\WP_Post $post, \WP_REST_Request $request, bool $creating) use ($adoptAttachment): void {
                $adoptAttachment((int) $post->ID);
            }, 20, 3);
            (new PublicMediaVideoRoutes(new MediaVideoPageQuery($publicMedia, $publicAssets, $publicUsages, $publicVideos, $publicStatus, null, $publicRelated, null, $publicClaims, $publicEvidence, $publicSources), $historicPublicRouteService))->register();
            (new PublicVideoSitemapRoutes($publicVideos, $publicStatus))->register();
            $publicMediaDelivery = \NHK\Core\Application\Media\PublicMediaAssetDelivery::fromEnvironment($publicAssets, $publicMedia);
            if ($publicMediaDelivery !== null) (new PublicMediaAssetRoutes($publicMediaDelivery))->register();
            (new PublicKnowledgeRoutes(new KnowledgePageQuery($publicClaims, $publicEvidence, $publicSources, $publicStatus)))->register();
        }
        add_action('rest_api_init', static function () use (&$sharedAttachmentBridge, &$captureRepository, $claimOwnerUrl): void {
            (new HealthCheck(new MigrationStatus()))->register_routes();
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) return;
            $media = new WpdbMediaRepository($wpdb); $assets = new WpdbMediaAssetRepository($wpdb); $usages = new WpdbMediaUsageRepository($wpdb); $videos = new WpdbVideoRepository($wpdb); $claims = new WpdbKnowledgeRepository($wpdb); $sources = new WpdbSourceRepository($wpdb); $evidence = new WpdbEvidenceRepository($wpdb); $authority = new WpdbAuthorityRepository($wpdb);
            (new ReadApi($media, $assets, $usages, $videos, $claims, $sources, $evidence, new MigrationStatus()))->register();
            $types = new EntityTypeRegistry();
            CanonicalEntityTypeCatalog::registerInto($types);
            $endpoints = new EndpointTypeRegistry(); CoreEndpointResolverRegistrar::register($endpoints, $types, $authority, $media, $videos, $claims, $sources, $evidence); $graphRepository = new WpdbGraphRepository($wpdb); $predicates = new PredicateRegistry(); $classifiedAsPolicy = new \NHK\Core\Application\Graph\ClassifiedAsPolicy(); $graphService = new GraphService($graphRepository, $endpoints, $predicates, new WpdbAuditSink(), new \NHK\Core\Application\Graph\ClassificationHierarchyPolicy($authority, $graphRepository), $classifiedAsPolicy);
            $publicStatus = new MigrationStatus();
            $publicContexts = new StructuralContextQuery($graphService, $authority);
            $publicIdentityRepository = new WpdbPublicIdentityRepository($wpdb);
            \NHK\Core\Application\PublicIdentity\PublicIdentityReadRegistry::register($publicIdentityRepository);
            $publicIdentityService = new \NHK\Core\Application\PublicIdentity\PublicIdentityService($publicIdentityRepository, static fn (string $slug): bool => false);
            $publicRoutes = new PublicRouteResolver($authority, $types, $publicContexts);
            $publicEligibility = new PublicEntityEligibilityPolicy($authority, $types, $publicRoutes, $publicContexts);
            $entityMediaProjection = new EntityMediaProjection($media, $assets, $usages);
            $mediaCapabilities = \NHK\Core\Application\Media\MediaOwnerCapabilityRegistry::fromEndpointRegistry($endpoints);
            $mediaFinalReadback = new \NHK\Core\Application\Media\MediaEnrichmentCompletionPolicy(
                static function (string $type, string $id) use ($mediaCapabilities, $types, $authority, $publicEligibility, $publicRoutes): ?array {
                    $capability = $mediaCapabilities->forEndpoint($type);
                    if ($capability === null) return null;
                    $public = false;
                    if ($types->has($type)) {
                        $owner = $authority->findByCanonicalId($id);
                        $public = $owner instanceof \NHK\Core\Domain\Authority\AuthorityEntity
                            && $publicEligibility->evaluate($owner)->eligible
                            && $publicRoutes->path($owner) !== null;
                    }
                    return ['projection_required' => $capability->requiresProjection, 'public_required' => $public || $capability->requiresPublicSurface, 'frontend_required' => $capability->requiresFrontendReadback];
                },
                static function (string $type, string $id, string $mediaId, string $role) use ($entityMediaProjection): array {
                    $projection = $entityMediaProjection->forEntity($type, $id);
                    $representative = is_array($projection['representative'] ?? null) ? $projection['representative'] : [];
                    $match = (($role === \NHK\Core\Domain\Media\MediaUsageRoleRegistry::REPRESENTATIVE || $role === '') && ($representative['media_id'] ?? '') === $mediaId)
                        ? $representative : null;
                    if ($match === null && $role !== \NHK\Core\Domain\Media\MediaUsageRoleRegistry::REPRESENTATIVE) {
                        foreach ((array) ($projection['gallery'] ?? []) as $item) if (is_array($item) && ($item['media_id'] ?? '') === $mediaId && ($item['role'] ?? '') === $role) { $match = $item; break; }
                    }
                    return $match === null ? ['status' => 'stale', 'media_id' => $mediaId] : ['status' => 'verified', 'media_id' => $mediaId, 'url' => $match['url'] ?? ''];
                },
                static function (string $type, string $id, string $mediaId, string $role) use ($entityMediaProjection, $authority, $publicEligibility, $publicRoutes, $types): array {
                    if (!$types->has($type)) return ['status' => 'stale', 'media_id' => $mediaId];
                    $owner = $authority->findByCanonicalId($id);
                    if (!$owner instanceof \NHK\Core\Domain\Authority\AuthorityEntity || !$publicEligibility->evaluate($owner)->eligible || $publicRoutes->path($owner) === null) return ['status' => 'stale', 'media_id' => $mediaId];
                    $projection = $entityMediaProjection->forEntity($type, $id);
                    $representative = is_array($projection['representative'] ?? null) ? $projection['representative'] : [];
                    return ($representative['media_id'] ?? '') === $mediaId ? ['status' => 'verified', 'media_id' => $mediaId, 'route' => $publicRoutes->path($owner)] : ['status' => 'stale', 'media_id' => $mediaId];
                },
            );
            $publicCollection = new PublicEntityCollectionQuery($authority, $types, new PublicIdentityContract($types), $publicEligibility, $publicRoutes, new BrandAggregationQuery($graphService, $authority, $types, $publicRoutes, $publicEligibility), static fn (): bool => $publicStatus->authorityStorageReady(), $entityMediaProjection, new EntityKnowledgeProjection($claims, $evidence, $sources, $publicStatus));
            $governanceRuntime = GovernanceRuntimeFactory::fromWordPress($wpdb, $sharedAttachmentBridge);
            $stagingScopeVerifier = $governanceRuntime->stagingScopeVerifier ?? new \NHK\Core\Application\Governance\StagingAcceptanceScopeVerifier(
                static function (): string { return defined('WP_ENVIRONMENT_TYPE') ? strtolower((string) constant('WP_ENVIRONMENT_TYPE')) : (function_exists('wp_get_environment_type') ? strtolower((string) wp_get_environment_type()) : strtolower((string) (getenv('WP_ENVIRONMENT_TYPE') ?: 'unknown'))); },
                defined('NHK_STAGING_ACCEPTANCE_SCOPE_SECRET') ? (string) constant('NHK_STAGING_ACCEPTANCE_SCOPE_SECRET') : (string) (getenv('NHK_STAGING_ACCEPTANCE_SCOPE_SECRET') ?: ''),
                static function (array $scope, \NHK\Core\Domain\Capture\CaptureRecord $capture, array $input, array $assets): bool { return function_exists('apply_filters') && (bool) apply_filters('nhk_v3_staging_acceptance_admission', false, $scope, $capture, $input, $assets); },
                can: static fn (string $capability): bool => function_exists('current_user_can') && current_user_can($capability),
            );
            $proposalRepository = $governanceRuntime->proposals;
            $governance = $governanceRuntime->governance;
            $eligibility = $governanceRuntime->eligibility;
            (new AdminWorkbenchReadApi($media, $videos, $claims, $authority, $sources, $evidence, $graphService, $proposalRepository, $eligibility, $assets, $usages, new EntityProfileAdminProjection()))->register();
            $authorityService = new \NHK\Core\Application\Authority\AuthorityService($authority, $types, new \NHK\Core\Infrastructure\Authority\WpdbAuditSink(new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb)));
            $mediaService = new MediaService($media, $assets, $usages);
            $mediaBindingService = $governanceRuntime->mediaBinding ?? new MediaBindingService($media, $assets, $usages, $authority, $types, new WpdbMediaBindingOperationRepository($wpdb), stagingGuard: new \NHK\Core\Application\Governance\MediaBindingStagingGuard(static function (): string { return defined('WP_ENVIRONMENT_TYPE') ? strtolower((string) constant('WP_ENVIRONMENT_TYPE')) : (function_exists('wp_get_environment_type') ? strtolower((string) wp_get_environment_type()) : strtolower((string) (getenv('WP_ENVIRONMENT_TYPE') ?: 'unknown'))); }, [$stagingScopeVerifier, 'verifyBindingRequest'], static fn (string $capability): bool => function_exists('current_user_can') && current_user_can($capability)));
            $attachmentBridge = $sharedAttachmentBridge ?? new WordPressMediaAttachmentBridge($wpdb, $mediaService, $media, $assets);
            $sharedAttachmentBridge = $attachmentBridge;
            $knowledgeService = new KnowledgeService($claims, $sources, $evidence);
            $collectorBranchReader = static function (string $classificationId) use ($authority, $claims, $graphService): array {
                $classification = $authority->findByCanonicalId($classificationId);
                if (!$classification instanceof \NHK\Core\Domain\Authority\AuthorityEntity || $classification->entityType !== 'classification' || !$classification->active()) return ['status' => 'unavailable', 'reason' => 'CLASSIFICATION_NOT_AVAILABLE'];
                $items = []; $after = 0;
                try {
                    do {
                        $page = $graphService->findIncoming(new \NHK\Core\Domain\Graph\NodeReference('classification', $classificationId), 'about', $after, 200, false, 'knowledge');
                        foreach ((array) ($page['items'] ?? []) as $edge) {
                            if (!$edge instanceof \NHK\Core\Domain\Graph\GraphEdge || !$edge->isActive()) continue;
                            $claim = $claims->findByCanonicalId($edge->source->reference->endpoint_key);
                            if ($claim !== null && $claim->active && $claim->isPublic()) $items[$claim->canonicalId] = $claim;
                        }
                        $next = $page['next_cursor'] ?? null;
                        if ($next === null) break;
                        if (!is_int($next) || $next <= $after) return ['status' => 'unavailable', 'reason' => 'BRANCH_KNOWLEDGE_CURSOR_INVALID'];
                        $after = $next;
                    } while (true);
                } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'BRANCH_KNOWLEDGE_UNAVAILABLE']; }
                return ['status' => 'available', 'claims' => array_values($items), 'classification_revision' => $classification->revision];
            };
            $controlledApply = $governanceRuntime->controlledApply;
            $collectorMaintenance = new CollectorFacetMaintenanceService($claims, $collectorBranchReader, $governance, $controlledApply);
            add_filter('nhk_v3_collector_facet_maintenance_service', static fn (mixed $current): mixed => $current ?? $collectorMaintenance, 10, 1);
            $articleEditorial = new WpEditorialStateReader();
            $articlePreflight = new ArticleIngestPreflight(
                $endpoints,
                $predicates,
                $types,
                static function (string $type, string $id) use ($authority, $media, $videos, $claims, $sources, $evidence, $graphRepository): bool {
                    if (!\NHK\Core\Shared\Uuid\UuidCodec::isValid($id)) return false;
                    return match ($type) {
                        'brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product' => $authority->findByCanonicalId($id) !== null,
                        'media' => $media->findByCanonicalId($id) !== null,
                        'video' => $videos->findByCanonicalId($id) !== null,
                        'knowledge' => $claims->findByCanonicalId($id) !== null,
                        'source' => $sources->findByCanonicalId($id) !== null,
                        'evidence' => $evidence->findByCanonicalId($id) !== null,
                        'relation' => $graphRepository->findByUuid($id) !== null,
                        default => false,
                    };
                },
                static function (string $type, string $stableKey) use ($authority, $media, $claims, $sources): bool {
                    return match ($type) {
                        'brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product' => $authority->findByStableKey($type, $stableKey) !== null,
                        'media' => $media->findByStableKey($stableKey) !== null,
                        'knowledge' => $claims->findByStableKey($stableKey) !== null,
                        'source' => $sources->findByStableKey($stableKey) !== null,
                        default => false,
                    };
                }
            );
            $articleMedia = new ArticleMediaCoordinator($mediaService, $media, $assets, $usages, new \NHK\Core\Infrastructure\Media\WpdbArticleMediaBlueprintRepository($wpdb), null, $attachmentBridge);
            $articleCoordinator = new ArticleIngestCoordinator(new WpdbArticleOperationReceiptRepository($wpdb), $articlePreflight, new SemanticProposalPlanner(), $articleEditorial, $governance, $controlledApply, $proposalRepository, new WpdbDependencyRepository($wpdb), new ArticleVerificationReader(), $articleMedia, new WpEditorialPostStore($articleEditorial));
            $researchResolver = new McpSemanticContextResolver($authority, $types);
            $articlePublicRoutes = [
                'wp_post' => static fn (array $candidate): ?string => str_starts_with(trim((string) ($candidate['route'] ?? '')), '/') ? trim((string) $candidate['route']) : null,
                'video' => static fn (array $candidate): ?string => PublicRouteResolver::videoPath((string) ($candidate['title'] ?? ''), (string) ($candidate['external_id'] ?? '')),
            ];
            foreach (['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'] as $authorityType) {
                $articlePublicRoutes[$authorityType] = static function (array $candidate) use ($authority, $publicRoutes, $publicEligibility, $authorityType): ?string {
                    $entity = $authority->findByCanonicalId((string) ($candidate['target_id'] ?? ''));
                    return $entity && $entity->entityType === $authorityType && $publicEligibility->evaluate($entity)->eligible ? $publicRoutes->path($entity) : null;
                };
            }
            $articlePublicEligibility = new PublicEndpointEligibilityResolver($publicEligibility, $articlePublicRoutes);
            $articleResearch = new ArticleResearchPreflight(
                static function (array $input) use ($researchResolver): array {
                    $subject = is_array($input['subject'] ?? null) ? $input['subject'] : [];
                    $context = [];
                    if (isset($subject['type'])) {
                        $type = trim((string) $subject['type']);
                        if ($type !== '') $context[$type] = $subject;
                    } elseif (is_array($subject['subjects'] ?? null)) {
                        foreach ($subject['subjects'] as $item) {
                            $type = trim((string) ($item['type'] ?? ''));
                            if ($type !== '') $context[$type] = $item;
                        }
                    } else {
                        foreach ($subject as $type => $item) if (is_array($item) && trim((string) $type) !== '') $context[(string) $type] = $item;
                    }
                    if ($context === []) return ['status' => 'ambiguous', 'candidates' => []];
                    $resolved = $researchResolver->resolve($context);
                    foreach ($resolved['conflicts'] as $type => $conflict) return ['status' => 'ambiguous', 'candidates' => $resolved['candidates'][$type] ?? [], 'conflict' => $conflict];
                    foreach ($resolved['ambiguities'] as $type => $_reason) return ['status' => 'ambiguous', 'candidates' => $resolved['candidates'][$type] ?? []];
                    if (count($resolved['resolved']) !== count($context)) return ['status' => 'not_found', 'resolved' => $resolved['resolved']];
                    $subjects = array_values($resolved['resolved']);
                    return ['status' => 'resolved', 'primary' => $subjects[0], 'subjects' => $subjects, 'resolved' => $resolved['resolved']];
                },
                static function (array $input) use ($authority, $types, $claims, $sources, $evidence, $media, $assets, $usages, $videos, $graphService, $predicates, $mediaBindingService): array {
                    $started = microtime(true);
                    $timings = [];
                    $primary = is_array($input['subject_resolution']['primary'] ?? null) ? $input['subject_resolution']['primary'] : [];
                    $subjects = is_array($input['subject_resolution']['subjects'] ?? null) ? $input['subject_resolution']['subjects'] : ($primary !== [] ? [$primary] : []);
                    $subjectIds = array_values(array_unique(array_filter(array_map(static fn (mixed $subject): string => is_array($subject) ? trim((string) ($subject['id'] ?? '')) : '', $subjects))));
                    $posts = function_exists('get_posts') ? array_map(static fn (\WP_Post $post): array => ['id' => (string) $post->ID, 'title' => (string) $post->post_title, 'published' => $post->post_status === 'publish', 'subject_ids' => []], get_posts(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => 100, 'no_found_rows' => true])) : [];
                    $timings['editorial_inventory_ms'] = (int) round((microtime(true) - $started) * 1000);
                    $articlePostId = (int) ($input['article_context']['post_id'] ?? 0);
                    $defaultCategoryId = function_exists('get_option') ? (int) get_option('default_category', 0) : 0;
                    $currentCategories = $articlePostId > 0 && function_exists('get_the_category')
                        ? array_map(static fn ($category): array => ['id' => (int) $category->term_id, 'name' => (string) $category->name, 'slug' => (string) $category->slug, 'is_default' => (int) $category->term_id === $defaultCategoryId], (array) get_the_category($articlePostId))
                        : [];
                    $articleMedia = [];
                    if ($articlePostId > 0) {
                        $articleEndpoint = (function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1) . ':' . $articlePostId;
                        $suitabilityPolicy = new \NHK\Core\Application\Media\SemanticSuitabilityPolicy();
                        $primarySubjectId = trim((string) (($input['subject_resolution']['primary']['id'] ?? '')));
                        $subjectIdsForMedia = $primarySubjectId !== '' ? [$primarySubjectId] : $subjectIds;
                        $selectedMedia = is_array($input['article_context']['article_media']['selected'] ?? null)
                            ? $input['article_context']['article_media']['selected']
                            : [];
                        if (isset($selectedMedia['media_id'])) $selectedMedia = ['featured_primary' => $selectedMedia];
                        foreach (['featured_primary', 'inline_primary'] as $role) {
                            $usage = $usages->listByEndpoint('wp_post', $articleEndpoint, $role)[0] ?? null;
                            $explicit = is_array($selectedMedia[$role] ?? null) ? $selectedMedia[$role] : null;
                            $explicitId = trim((string) ($explicit['media_id'] ?? ''));
                            // A current explicit selection is authoritative
                            // input, including when it is later rejected for
                            // incompatibility. Never fall back to stale
                            // persisted usage after an explicit request.
                            $mediaItem = $explicitId !== ''
                                ? $media->findByCanonicalId($explicitId)
                                : ($usage !== null ? $media->findByCanonicalId($usage->mediaId) : null);
                            $selectionSource = $explicitId !== '' ? strtoupper(trim((string) ($explicit['selection_source'] ?? 'USER_EXPLICIT'))) : 'SYSTEM_AUTO';
                            $assessment = $mediaItem instanceof \NHK\Core\Domain\Media\Media
                                ? $suitabilityPolicy->evaluateMedia($mediaItem, $assets->listByMediaId($mediaItem->canonicalId), ['subject_ids' => $subjectIdsForMedia, 'current_capture_media' => $explicitId !== '', 'article_explicit_media' => $explicitId !== ''], $selectionSource, $role)
                                : ['requirement' => \NHK\Core\Application\Media\SemanticSuitabilityPolicy::OPTIONAL, 'availability' => \NHK\Core\Application\Media\SemanticSuitabilityPolicy::MISSING, 'suitability' => \NHK\Core\Application\Media\SemanticSuitabilityPolicy::UNKNOWN, 'valid_for_completeness' => false, 'diagnostic' => 'MEDIA_USAGE_INCOMPLETE'];
                            $effective = ($assessment['valid_for_completeness'] ?? false) === true && $mediaItem instanceof \NHK\Core\Domain\Media\Media && !$mediaItem->isSystemPlaceholder();
                            $articleMedia[$role] = [
                                'media_id' => $effective ? $mediaItem->canonicalId : null,
                                'persisted_media_id' => $mediaItem?->canonicalId,
                                'requested_media_id' => $explicitId !== '' ? $explicitId : null,
                                'selection_source' => $explicit['selection_source'] ?? null,
                                'selection_policy' => $explicit['selection_policy'] ?? null,
                                'placeholder' => !$effective,
                                'suitability' => $assessment['suitability'],
                                'availability' => $assessment['availability'],
                                'valid_for_completeness' => $effective,
                            ];
                            if (!$effective) $articleMedia['diagnostics'][] = ['code' => $assessment['diagnostic'] ?? 'MEDIA_USAGE_SEMANTIC_MISMATCH', 'slot' => $role, 'media_id' => $mediaItem?->canonicalId];
                        }
                        $articleMedia['diagnostics'] = array_values(array_filter((array) ($articleMedia['diagnostics'] ?? []), 'is_array'));
                        $articleMedia['media_complete'] = ($articleMedia['featured_primary']['valid_for_completeness'] ?? false) === true
                            && ($articleMedia['inline_primary']['valid_for_completeness'] ?? false) === true;
                        if (($articleMedia['featured_primary']['placeholder'] ?? true) === true) $articleMedia['diagnostics'][] = ['code' => 'ARTICLE_MEDIA_FEATURED_MISSING'];
                        if (($articleMedia['inline_primary']['placeholder'] ?? true) === true) $articleMedia['diagnostics'][] = ['code' => 'ARTICLE_MEDIA_INLINE_MISSING'];
                    }
                    $authorityRows = [];
                    foreach ($types->all() as $definition) foreach ($authority->listByType($definition->type) as $entity) $authorityRows[] = ['id' => $entity->canonicalId, 'type' => $entity->entityType, 'name' => $entity->canonicalName, 'active' => $entity->active()];
                    $branchKnowledge = [];
                    $branchKnowledgeSubjects = [];
                    if ($subjects !== []) {
                        try {
                            foreach ($subjects as $subject) {
                                $reference = new \NHK\Core\Domain\Graph\NodeReference((string) $subject['type'], (string) $subject['id']);
                                $after = 0;
                                do {
                                    $page = $graphService->findIncoming($reference, 'about', $after, 100, false, 'knowledge');
                                    foreach ((array) ($page['items'] ?? []) as $edge) {
                                        if (!$edge instanceof \NHK\Core\Domain\Graph\GraphEdge || !$edge->isActive()) continue;
                                        $claim = $claims->findByCanonicalId($edge->source->reference->endpoint_key);
                                        if ($claim === null) continue;
                                        $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
                                        $metadataSubject = trim((string) ($metadata['subject_id'] ?? ''));
                                        if ($subjectIds !== [] && $metadataSubject !== '' && !in_array($metadataSubject, $subjectIds, true)) continue;
                                        $branchKnowledge[$claim->canonicalId] = $claim;
                                        $branchKnowledgeSubjects[$claim->canonicalId][] = (string) $subject['id'];
                                    }
                                    if (count($branchKnowledge) >= 100) break;
                                    $next = $page['next_cursor'] ?? null;
                                    if ($next === null) break;
                                    if (!is_int($next) || $next <= $after) return ['status' => 'unavailable', 'reason' => 'GRAPH_RESEARCH_UNAVAILABLE'];
                                    $after = $next;
                                } while (true);
                            }
                        } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'GRAPH_RESEARCH_UNAVAILABLE']; }
                    }
                    $timings['graph_knowledge_ms'] = (int) round((microtime(true) - $started) * 1000);
                    $knowledgeRows = [];
                    $sourceRows = [];
                    $evidenceRows = [];
                    $knowledgeClaims = $branchKnowledge;
                    // Never scan the entire Knowledge corpus for a resolved
                    // subject. The Graph-owned branch above is the bounded
                    // canonical candidate set; the unscoped fallback is only
                    // retained for non-subject research calls.
                    if ($subjectIds === []) foreach (array_slice($claims->list(), 0, 100) as $claim) $knowledgeClaims[$claim->canonicalId] = $claim;
                    foreach (array_slice($knowledgeClaims, 0, 100) as $claim) {
                        $claimMetadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
                        $claimSubjectId = trim((string) ($claimMetadata['subject_id'] ?? ''));
                        $claimSubjectIds = array_values(array_unique(array_filter(array_map('strval', (array) ($branchKnowledgeSubjects[$claim->canonicalId] ?? [])))));
                        $claimEvidence = array_slice($evidence->listByClaim($claim->canonicalId), 0, 5);
                        $evidenceForClaim = [];
                        foreach ($claimEvidence as $item) {
                            $source = $sources->findByCanonicalId($item->sourceId);
                            $evidenceForClaim[] = ['id' => $item->canonicalId, 'relation' => $item->relation, 'excerpt' => $item->excerpt, 'locator' => $item->locator, 'active' => $item->active, 'public' => $item->isPublic(), 'source' => $source ? ['id' => $source->canonicalId, 'title' => $source->title, 'locator' => $source->locator, 'public' => $source->isPublic(), 'active' => $source->active] : null];
                            if (count($evidenceRows) < 200) $evidenceRows[] = $evidenceForClaim[array_key_last($evidenceForClaim)];
                            if ($source !== null && count($sourceRows) < 50) $sourceRows[$source->canonicalId] = ['id' => $source->canonicalId, 'title' => $source->title, 'locator' => $source->locator, 'public' => $source->isPublic(), 'active' => $source->active];
                        }
                        $support = array_values(array_filter($evidenceForClaim, static fn (array $item): bool => $item['relation'] === 'supports' && $item['active'] === true));
                        $knowledgeRows[] = ['id' => $claim->canonicalId, 'subject_id' => $claimSubjectId !== '' ? $claimSubjectId : ($claimSubjectIds[0] ?? ''), 'subject_ids' => $claimSubjectIds, 'text' => $claim->claimText, 'scope' => $claim->claimType, 'active' => $claim->active, 'public' => $claim->isPublic(), 'evidence' => $evidenceForClaim, 'evidence_status' => $support === [] ? ($claimEvidence === [] ? 'NO_EVIDENCE' : 'INSUFFICIENT_EVIDENCE') : 'SUPPORTED_WITHIN_SCOPE'];
                    }
                    $timings['knowledge_evidence_ms'] = (int) round((microtime(true) - $started) * 1000);
                    $mediaRows = [];
                    foreach ($subjectIds as $subjectId) {
                        foreach ($usages->listByEndpoint('classification', $subjectId) as $usage) {
                            $mediaItem = $media->findByCanonicalId($usage->mediaId);
                            if ($mediaItem === null) continue;
                            $mediaRows[$mediaItem->canonicalId] = ['id' => $mediaItem->canonicalId, 'subject_ids' => [$subjectId], 'ready' => $mediaItem->readiness === 'ready', 'public' => $mediaItem->active];
                        }
                    }
                    $videoRows = [];
                    $branchVideoIds = [];
                    $relations = [];
                    if ($subjects !== []) {
                        try {
                            $query = new RelatedSemanticQuery($graphService, new PredicateTraversalPolicy($predicates));
                            foreach ($subjects as $subject) {
                                $ref = new \NHK\Core\Domain\Graph\NodeReference((string) $subject['type'], (string) $subject['id']);
                                $related = $query->query($ref, [], 2, 50);
                                foreach ($related['items'] as $item) {
                                    $targetType = (string) ($item['target_entity_type'] ?? '');
                                    $targetId = (string) ($item['target_entity_id'] ?? '');
                                    $relations[$targetType . ':' . $targetId] = ['class' => $item['relationship_class'], 'predicate' => $item['best_path'][array_key_last($item['best_path'])]['predicate'] ?? '', 'target_id' => $targetId, 'target_type' => $targetType, 'path' => $item['best_path'], 'reason' => 'registered Graph traversal'];
                                    if ($targetType === 'video' && $targetId !== '') $branchVideoIds[$targetId][] = $subject['id'];
                                }
                                foreach ($graphService->findIncoming($ref, null, 0, 50)['items'] as $edge) { $postRef = $edge->source->reference; if ($postRef->endpoint_type !== 'wp_post') continue; foreach ($posts as &$post) if ($post['id'] === substr($postRef->endpoint_key, strpos($postRef->endpoint_key, ':') + 1)) $post['subject_ids'][] = $subject['id']; unset($post); }
                            }
                            $relations = array_values($relations);
                            foreach ($branchVideoIds as $videoId => $videoSubjectIds) {
                                $video = $videos->findByCanonicalId((string) $videoId);
                                if ($video === null) continue;
                                $videoRows[(string) $videoId] = ['id' => $video->canonicalId, 'subject_ids' => array_values(array_unique($videoSubjectIds)), 'public' => $video->active && $video->hasValidPublicReference()];
                            }
                            $posts = array_values(array_filter($posts, static fn (array $post): bool => array_intersect($post['subject_ids'], $subjectIds) !== []));
                        } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'GRAPH_RESEARCH_UNAVAILABLE']; }
                    }
                    $timings['total_ms'] = (int) round((microtime(true) - $started) * 1000);
                    return ['status' => 'available', 'posts' => array_slice($posts, 0, 100), 'current_categories' => $currentCategories, 'article_media' => $articleMedia, 'categories' => function_exists('get_categories') ? array_map(static fn ($category): array => ['id' => (int) $category->term_id, 'name' => $category->name, 'slug' => $category->slug, 'is_default' => (int) $category->term_id === $defaultCategoryId], get_categories(['hide_empty' => false, 'number' => 50])) : [], 'authority' => $authorityRows, 'knowledge' => array_values($knowledgeRows), 'sources' => array_values($sourceRows), 'evidence' => array_slice($evidenceRows, 0, 200), 'media' => array_values($mediaRows), 'videos' => array_values($videoRows), 'relations' => array_slice($relations, 0, 50), 'diagnostics' => ['timings_ms' => $timings, 'bounds' => ['posts' => 100, 'knowledge' => 100, 'evidence_per_claim' => 5, 'evidence_total' => 200, 'relations' => 50]]];
                },
                [$articlePublicEligibility, 'evaluate'],
            );
            $articleHandler = new McpArticleIngestHandler($articleCoordinator, $articlePreflight, $articleEditorial, $articleMedia, $articleResearch, static fn (): mixed => function_exists('apply_filters') ? apply_filters('nhk_v3_article_reconciliation_orchestrator', null) : null);
            $articlePreflightHandoff = new CaptureArticlePreflightHandoff();
            (new GovernanceApi($governance, $eligibility, $controlledApply, $endpoints))->register();
            $videoRelationAdmin = new \NHK\Core\Application\Video\VideoRelationAdminService($governance, $proposalRepository, $videos, $authority, $knowledgeService, $claims, $sources, $evidence);
            (new VideoRelationAdminApi($videoRelationAdmin))->register();
            (new SearchApi($media, $videos, $claims, $authority, $types, $publicStatus, $publicCollection, $claimOwnerUrl))->register();
            (new EntityApi($authority, $types, $publicStatus, $publicCollection))->register();
            (new GraphApi($graphService, new MigrationStatus()))->register();
            $wordpressAttachments = new WordPressMediaAttachmentIngestor($attachmentBridge);
            $existingMediaResolver = new \NHK\Core\Application\Media\ExistingMediaReferenceResolver($media, $assets, $wordpressAttachments);
            $mediaUrlOrigin = static function (string $value): string {
                $parts = parse_url($value);
                if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || trim((string) ($parts['host'] ?? '')) === '') return '';
                return 'https://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
            };
            $existingAttachmentUrlResolver = new \NHK\Core\Infrastructure\Media\WordPressAttachmentUrlResolver(
                $wpdb,
                array_values(array_filter(array_unique([$mediaUrlOrigin((string) site_url()), $mediaUrlOrigin((string) home_url())]))),
            );
            $mediaBatchUpload = new MediaBatchUploadService($wordpressAttachments);
            $imageIngest = new ImageIngestEntrypoint(
                static fn (string $idempotencyKey, array $metadata, array $files, array $items): array => $mediaBatchUpload->upload($idempotencyKey, $metadata, $files, $items),
                static fn (mixed $references): array => \NHK\Core\Infrastructure\Mcp\TrustedProvidedFileMaterializer::materialize($references),
            );
            $mcpNeighborhood = new SemanticNeighborhoodQuery(new RelatedSemanticQuery($graphService, new PredicateTraversalPolicy(new PredicateRegistry())));
            $canonicalInventory = self::canonicalInventory($types, $authority, $media, $videos, $claims, $sources, $evidence);
            $graphInventory = new GraphInventoryService($graphRepository, $endpoints, $predicates);
            $relationBackfill = self::relationBackfill($canonicalInventory, $graphInventory);
            $relationshipRead = new RelationshipReadService($endpoints, $predicates, $graphRepository, null, null, [
                'media_usage' => new \NHK\Core\Application\Graph\MediaUsageRelationshipAdapter($usages),
                'evidence' => new \NHK\Core\Application\Graph\EvidenceRelationshipAdapter($evidence, $claims, $sources),
            ]);
            $mcpRead = new McpReadHandler($authority, $types, $media, $assets, $usages, $videos, $claims, $evidence, new MigrationStatus(), $sources, null, new McpSemanticContextResolver($authority, $types), $wordpressAttachments, $mcpNeighborhood, $canonicalInventory, $graphInventory, $relationBackfill, null, $captureRepository, $relationshipRead);
            // Relations are governed semantic children of Capture article
            // reconciliation (for example, a post --about--> classification
            // binding). Register the existing relation boundary alongside
            // the other runtime types so Capture never fails merely because
            // its typed Governance plan is a relation.
            $automationTypes = \NHK\Core\Application\Governance\GovernanceAutomationTypeRegistry::all($types);
            $automationResolver = new \NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver($automationTypes, new \NHK\Core\Infrastructure\Governance\WpOptionAutomationPolicyStorage($automationTypes, registeredKeys: \NHK\Core\Application\Governance\GovernanceAutomationPolicyRegistry::keys($automationTypes)));
            $publicIdentityRepository = new \NHK\Core\Infrastructure\PublicIdentity\WpdbPublicIdentityRepository($wpdb);
            $publicVideoFrontendReader = new \NHK\Core\Application\Media\MediaVideoPageQuery($media, $assets, $usages, $videos, new MigrationStatus(), null, null, null, $claims, $evidence, $sources);
            $publicProjectionVerifier = new \NHK\Core\Application\Governance\PublicProjectionVerifier(
                static function (string $ownerType, string $id) use ($types, $authority, $media, $videos, $claims, $sources, $evidence): mixed {
                    return match ($ownerType) {
                        'video' => $videos->findByCanonicalId($id),
                        'media' => $media->findByCanonicalId($id),
                        'knowledge' => $claims->findByCanonicalId($id),
                        'source' => $sources->findByCanonicalId($id),
                        'evidence' => $evidence->findByCanonicalId($id),
                        default => $types->has($ownerType) ? $authority->findByCanonicalId($id) : null,
                    };
                },
                static function (string $ownerType, mixed $owner) use ($publicEligibility, $publicRoutes, $publicIdentityRepository, $publicVideoFrontendReader): mixed {
                    if ($ownerType === 'video' && $owner instanceof \NHK\Core\Domain\Video\Video) {
                        $projection = (new \NHK\Core\Application\Video\VideoFrontendProjection($publicIdentityRepository))->project($owner);
                        $path = is_array($projection['item'] ?? null) ? (string) ($projection['item']['public_url'] ?? '') : '';
                        $detail = $publicVideoFrontendReader->videoDetail($owner->canonicalId);
                        $route = preg_match('#^/video/([^/]+)/$#', $path, $matches) === 1 ? $publicVideoFrontendReader->videoBySlug(rawurldecode((string) $matches[1])) : null;
                        $archive = $publicVideoFrontendReader->videoArchive(1, 100);
                        $archiveItems = array_values(array_filter((array) ($archive['items'] ?? []), static fn (mixed $item): bool => is_array($item) && (string) ($item['canonical_id'] ?? '') === $owner->canonicalId && (string) ($item['public_url'] ?? '') === $path));
                        $home = function_exists('apply_filters') ? (array) apply_filters('nhk_v3_home_semantic_modules', ['entities' => [], 'media' => [], 'videos' => [], 'knowledge' => [], 'hubs' => [], 'clock_groups' => [], 'explore_next' => [], 'latest_feed' => []]) : [];
                        $homeItems = array_values(array_filter((array) ($home['videos'] ?? []), static fn (mixed $item): bool => is_array($item) && (string) ($item['canonical_id'] ?? '') === $owner->canonicalId && (string) ($item['url'] ?? '') === $path));
                        $homeApplicable = (int) ($home['videos_total'] ?? 0) > 0 || count($homeItems) === 1;
                        $detailOk = is_array($detail) && (string) ($detail['canonical_id'] ?? '') === $owner->canonicalId && (string) ($detail['public_url'] ?? '') === $path;
                        $routeOk = is_array($route) && (string) ($route['canonical_id'] ?? '') === $owner->canonicalId && (string) ($route['public_url'] ?? '') === $path;
                        $ok = ($projection['frontend_available'] ?? false) === true && $detailOk && $routeOk && count($archiveItems) === 1 && $homeApplicable;
                        return ['frontend_available' => $ok, 'projection_readback' => $ok, 'public_eligible' => $ok, 'route' => $path, 'blockers' => $ok ? [] : ['VIDEO_FRONTEND_READBACK_INCOMPLETE']];
                    }
                    if ($owner instanceof \NHK\Core\Domain\Authority\AuthorityEntity) return $publicEligibility->evaluate($owner)->eligible ? $publicRoutes->path($owner) : null;
                    return null;
                },
            );
            $mcpGovernance = new McpGovernanceHandler($governance, $eligibility, $controlledApply, $automationResolver, $endpoints, [$publicProjectionVerifier, 'verify']);
            (new AdminMediaUsageApi($mediaBindingService, $mcpGovernance, $mediaBatchUpload))->register();
            $relationState = static function (array $plan) use ($graphService, $predicates): array {
                $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
                $sourceType = strtolower(trim((string) ($payload['source_type'] ?? '')));
                $sourceUuid = trim((string) ($payload['source_uuid'] ?? ''));
                $targetType = strtolower(trim((string) ($payload['target_type'] ?? '')));
                $targetUuid = trim((string) ($payload['target_uuid'] ?? ''));
                $predicate = strtolower(trim((string) ($payload['predicate'] ?? '')));
                if ($sourceType === '' || $sourceUuid === '' || $targetType === '' || $targetUuid === '' || $predicate === '') return [];
                $source = new \NHK\Core\Domain\Graph\NodeReference($sourceType, $sourceUuid);
                $target = new \NHK\Core\Domain\Graph\NodeReference($targetType, $targetUuid);
                try {
                    $edge = $graphService->findEdge($source, $predicate, $target);
                    if ($edge !== null && $edge->isActive()) return ['status' => 'ACTIVE', 'canonical_id' => $edge->edge_uuid, 'revision' => $edge->revision, 'active' => true, 'direction' => 'SOURCE_TO_TARGET'];
                    $definition = $predicates->get($predicate);
                    if ($definition->outbound_cardinality === 'ONE') {
                        foreach ((array) ($graphService->findOutgoing($source, $predicate, 0, 200, false, $targetType)['items'] ?? []) as $candidate) {
                            if ($candidate instanceof \NHK\Core\Domain\Graph\GraphEdge && $candidate->isActive() && $candidate->target->reference->endpoint_key !== $targetUuid) return ['status' => 'CARDINALITY_CONFLICT', 'reason' => 'OUTBOUND_CARDINALITY_ONE'];
                        }
                    }
                    if ($definition->inbound_cardinality === 'ONE') {
                        foreach ((array) ($graphService->findIncoming($target, $predicate, 0, 200, false, $sourceType)['items'] ?? []) as $candidate) {
                            if ($candidate instanceof \NHK\Core\Domain\Graph\GraphEdge && $candidate->isActive() && $candidate->source->reference->endpoint_key !== $sourceUuid) return ['status' => 'CARDINALITY_CONFLICT', 'reason' => 'INBOUND_CARDINALITY_ONE'];
                        }
                    }
                    // Video's historical compatibility read-back permits the
                    // same logical about relation to be stored target -> Video.
                    // This is a read-only identity normalization; it never
                    // creates an inverse edge or broadens other predicates.
                    if ($predicate === 'about' && $sourceType === 'video') {
                        $inverse = $graphService->findIncoming($source, 'about', 0, 200, true);
                        foreach ((array) ($inverse['items'] ?? []) as $candidate) {
                            if (!$candidate instanceof \NHK\Core\Domain\Graph\GraphEdge || !$candidate->isActive()) continue;
                            $candidateSource = $candidate->source->reference;
                            if ($candidateSource->endpoint_type === $targetType && $candidateSource->endpoint_key === $targetUuid) return ['status' => 'ACTIVE', 'canonical_id' => $candidate->edge_uuid, 'revision' => $candidate->revision, 'active' => true, 'direction' => 'INVERSE'];
                        }
                    }
                    if ($predicate === 'about' && $targetType === 'video') {
                        $forward = $graphService->findOutgoing($target, 'about', 0, 200, true);
                        foreach ((array) ($forward['items'] ?? []) as $candidate) {
                            if (!$candidate instanceof \NHK\Core\Domain\Graph\GraphEdge || !$candidate->isActive()) continue;
                            $candidateTarget = $candidate->target->reference;
                            if ($candidateTarget->endpoint_type === $sourceType && $candidateTarget->endpoint_key === $sourceUuid) return ['status' => 'ACTIVE', 'canonical_id' => $candidate->edge_uuid, 'revision' => $candidate->revision, 'active' => true, 'direction' => 'INVERSE'];
                        }
                    }
                } catch (\Throwable) {
                    return [];
                }
                return [];
            };
            // Typed Capture relation intents must resolve active canonical
            // endpoints and exact endpoint revisions before Governance sees a
            // proposal. This closure is read-only; it never creates or
            // updates semantic records.
            $relationIntentEndpointState = static function (\NHK\Core\Domain\Graph\NodeReference $reference) use ($authority, $media, $videos, $claims, $sources, $evidence, $endpoints): ?array {
                $record = match ($reference->endpoint_type) {
                    'brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product' => $authority->findByCanonicalId($reference->endpoint_key),
                    'media' => $media->findByCanonicalId($reference->endpoint_key),
                    'video' => $videos->findByCanonicalId($reference->endpoint_key),
                    'knowledge' => $claims->findByCanonicalId($reference->endpoint_key),
                    'source' => $sources->findByCanonicalId($reference->endpoint_key),
                    'evidence' => $evidence->findByCanonicalId($reference->endpoint_key),
                    'wp_post' => function_exists('get_post') ? get_post((int) (explode(':', $reference->endpoint_key, 2)[1] ?? 0)) : null,
                    default => null,
                };
                if (!is_object($record)) return null;
                $active = match ($reference->endpoint_type) {
                    'brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product' => method_exists($record, 'active') && $record->active(),
                    'wp_post' => (string) ($record->post_status ?? '') !== 'trash',
                    default => ($record->active ?? false) === true,
                };
                $resolver = $endpoints->resolver($reference->endpoint_type);
                if (!$resolver instanceof \NHK\Core\Contracts\Graph\EndpointRevisionReader) return null;
                $revision = $resolver->revision($reference);
                return ['active' => $active, 'revision' => $revision, 'family' => $record->payload['family'] ?? null];
            };
            $relationIntentState = static function (array $packet) use ($graphService): array {
                $source = new \NHK\Core\Domain\Graph\NodeReference((string) ($packet['source_type'] ?? ''), (string) ($packet['source_uuid'] ?? ''));
                $target = new \NHK\Core\Domain\Graph\NodeReference((string) ($packet['target_type'] ?? ''), (string) ($packet['target_uuid'] ?? ''));
                try {
                    $edge = $graphService->findEdge($source, (string) ($packet['predicate'] ?? ''), $target);
                    if ($edge === null) return [];
                    return ['status' => $edge->isActive() ? 'ACTIVE' : 'RETIRED', 'canonical_id' => $edge->edge_uuid, 'revision' => $edge->revision];
                } catch (\Throwable) {
                    return [];
                }
            };
            $explicitRelationIntentPlanner = new \NHK\Core\Application\Graph\ExplicitRelationIntentPlanner($endpoints, $predicates, $relationIntentEndpointState, $relationIntentState, $classifiedAsPolicy);
            $relationProposalReconciliation = new RelationProposalReconciliationService(
                $mcpGovernance,
                $governance,
                $endpoints,
                static fn (string $proposalId): array => $mcpGovernance->apply($proposalId),
                $automationResolver,
                static fn (string $capability): bool => current_user_can($capability),
                static fn (): string => function_exists('get_current_user_id') ? (string) get_current_user_id() : '0',
                $relationState,
            );
            $clockTypeLifecycle = new \NHK\Core\Application\Authority\ClockTypeCreationLifecycle(
                new \NHK\Core\Application\Authority\AuthorityIntentPlanner($authority, $types),
                new \NHK\Core\Application\Governance\GovernedAuthorityPlanExecutor($mcpGovernance),
                $authority,
            );
            add_filter('nhk_v3_clock_type_creation_lifecycle', fn (mixed $current): mixed => $current ?? $clockTypeLifecycle, 10, 1);
            if ($governanceRuntime->videoReconciliation !== null) {
                $governanceRuntime->videoReconciliation->setScopeIssuer(static function (string $captureId, array $plan) use ($captureRepository, $stagingScopeVerifier): array {
                    $capture = $captureRepository->findById($captureId);
                    if (!$capture instanceof CaptureRecord) throw new \RuntimeException('STAGING_CAPTURE_NOT_FOUND');
                    return $stagingScopeVerifier->issueForVideoPlan($capture, $plan);
                });
            }
            $captureClaimReuse = new ClaimReusePolicy(static function (array $candidate) use ($claims): array {
                $subjectId = trim((string) ($candidate['subject_id'] ?? ''));
                $scope = trim((string) ($candidate['scope'] ?? ''));
                if ($subjectId === '' || $scope === '') return [];
                $matches = [];
                foreach (array_slice($claims->list(), 0, 200) as $claim) {
                    $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
                    if ((string) ($metadata['subject_id'] ?? '') !== $subjectId || (string) ($metadata['scope'] ?? '') !== $scope) continue;
                    $matches[] = [
                        'claim_id' => $claim->canonicalId,
                        'claim_revision' => $claim->revision,
                        'text' => $claim->claimText,
                        'subject_id' => $subjectId,
                        'scope' => $scope,
                        'provenance' => (string) ($claim->provenance['origin'] ?? 'CANONICAL_KNOWLEDGE'),
                        'evidence_status' => (string) ($metadata['evidence_status'] ?? ''),
                    ];
                }
                return $matches;
            });
            $videoKnowledgeEnrichment = new VideoKnowledgeEnrichmentPlanner(new \NHK\Core\Application\Knowledge\KnowledgeEnrichmentPlanner($claims, $evidence, $sources));
            $videoEditorialResume = null;
            $canonicalDependencies = new CanonicalDependencyValidator($claims, $sources, $evidence);
            $knowledgeRepairPreview = new \NHK\Core\Application\Knowledge\KnowledgeRepairPreviewService($claims, $evidence, $graphService);
            $videoRelationCandidates = new VideoRelationCandidatePlanner(new PredicateRegistry(), $evidence, $claims, $sources, $canonicalDependencies);
            $videoCompleteness = new \NHK\Core\Application\Video\VideoCompletenessReconciliationService($videos, $graphService, $canonicalDependencies, new VideoCompletenessPolicy());
            $captureGovernance = new GovernedCaptureContinuationService(
                $mcpGovernance,
                static fn (string $proposalId): array => $mcpGovernance->apply($proposalId),
                $automationResolver,
                static fn (string $capability): bool => current_user_can($capability),
                $captureClaimReuse,
                new CaptureVideoProvenancePlanner(new \NHK\Core\Application\Video\VideoThumbnailSelector(\NHK\Core\Application\Video\VideoThumbnailSelector::wordpressProbe(...))),
                $governanceRuntime->videoReconciliation,
                static function (array $plan) use ($sources, $claims, $evidence, $videos, $proposalRepository): array {
                    $provenance = is_array($plan['capture_video_provenance'] ?? null) ? $plan['capture_video_provenance'] : $plan;
                    $dependencies = (array) ($provenance['dependencies'] ?? []);
                    $sourcePayload = is_array(($dependencies[0]['payload'] ?? null)) ? $dependencies[0]['payload'] : [];
                    $claimPayload = is_array(($dependencies[1]['payload'] ?? null)) ? $dependencies[1]['payload'] : [];
                    $source = trim((string) ($sourcePayload['stable_key'] ?? '')) !== '' ? $sources->findByStableKey((string) $sourcePayload['stable_key']) : null;
                    $claim = trim((string) ($claimPayload['stable_key'] ?? '')) !== '' ? $claims->findByStableKey((string) $claimPayload['stable_key']) : null;
                    $evidenceRows = $claim !== null ? $evidence->listByClaim($claim->canonicalId) : [];
                    $proposal = null;
                    $key = trim((string) ($provenance['video_proposal']['idempotency_key'] ?? ''));
                    if ($key !== '') $proposal = $proposalRepository->findByIdempotencyKey($key);
                    $videoId = trim((string) ($provenance['video_proposal']['subject_id'] ?? $provenance['video_proposal']['payload']['canonical_id'] ?? ''));
                    $video = $videoId !== '' ? $videos->findByCanonicalId($videoId) : null;
                    $about = is_array($provenance['relation'] ?? null) ? $provenance['relation'] : [];
                    return [
                        'subject' => isset($about['target_uuid']) ? ['id' => (string) $about['target_uuid'], 'type' => (string) ($about['target_type'] ?? '')] : null,
                        'source' => $source === null ? null : ['canonical_id' => $source->canonicalId, 'revision' => $source->revision, 'active' => $source->active, 'title' => $source->title, 'source_type' => $source->sourceType, 'locator' => $source->locator, 'metadata' => $source->metadata],
                        'claim' => $claim === null ? null : ['canonical_id' => $claim->canonicalId, 'revision' => $claim->revision, 'active' => $claim->active, 'claim_text' => $claim->claimText, 'claim_type' => $claim->claimType, 'provenance' => $claim->provenance],
                        'evidence' => array_map(static fn ($item): array => ['canonical_id' => $item->canonicalId, 'revision' => $item->revision, 'claim_id' => $item->claimId, 'source_id' => $item->sourceId, 'relation' => $item->relation, 'excerpt' => $item->excerpt, 'locator' => $item->locator, 'active' => $item->active, 'metadata' => $item->metadata], $evidenceRows),
                        'video' => $video === null ? null : ['canonical_id' => $video->canonicalId, 'revision' => $video->revision, 'active' => $video->active],
                        'proposal' => $proposal === null ? null : [$proposal->id, $proposal->revision, $proposal->state->value, $proposal->subjectId],
                    ];
                },
                new \NHK\Core\Application\Capture\CaptureOrchestrationBudget(5000),
                static function (string $ignoredCaptureId, string $phase, array $receipt) use ($captureRepository): void {
                    $captureId = trim($ignoredCaptureId);
                    if ($captureId === '') return;
                    $record = $captureRepository->findById($captureId);
                    if (!$record instanceof CaptureRecord) return;
                    $receipts = CapturePhaseReceiptReducer::append($record->phaseReceipts, $phase, $receipt);
                    $captureRepository->save(new CaptureRecord($record->captureId, $record->idempotencyKey, $record->requestFingerprint, $record->stage, $record->status, $record->articleId, $record->articleStateToken, $record->assets, $record->context, $record->diagnostics, $receipts, $record->revision + 1, $record->createdAt, gmdate('Y-m-d H:i:s.u')));
                },
                videoRelations: $videoRelationCandidates,
                videoEditorialResume: $videoEditorialResume,
                videoCompleteness: $videoCompleteness,
                proposalReconciliation: static function (\NHK\Core\Domain\Governance\Proposal $proposal, array $eligibility, array $control) use ($relationProposalReconciliation): array {
                    return $relationProposalReconciliation->reconcile($proposal, $control);
                },
                relationState: $relationState,
                videoScopeIssuer: static function (string $captureId, array $plan) use ($captureRepository, $stagingScopeVerifier): array {
                    $capture = $captureRepository->findById($captureId);
                    if (!$capture instanceof CaptureRecord) throw new \RuntimeException('STAGING_CAPTURE_NOT_FOUND');
                    return $stagingScopeVerifier->issueForVideoPlan($capture, $plan);
                },
                pendingVideoProposals: $proposalRepository,
                dependencyScopeIssuer: static function (string $captureId, array $plan) use ($captureRepository, $stagingScopeVerifier): array {
                    $capture = $captureRepository->findById($captureId);
                    if (!$capture instanceof CaptureRecord) throw new \RuntimeException('STAGING_CAPTURE_NOT_FOUND');
                    return $stagingScopeVerifier->issueForCaptureDependencyPlan($capture, $plan);
                },
                relationScopeIssuer: static function (string $captureId, array $plan) use ($captureRepository, $stagingScopeVerifier, $endpoints): array {
                    $capture = $captureRepository->findById($captureId);
                    if (!$capture instanceof CaptureRecord) throw new \RuntimeException('STAGING_CAPTURE_NOT_FOUND');
                    $payload = is_array($plan['payload'] ?? null) ? $plan['payload'] : [];
                    $payload = (new \NHK\Core\Application\Graph\RelationRevisionBinder($endpoints))->bind($payload);
                    $plan['payload'] = $payload;
                    $scope = $stagingScopeVerifier->issueForCaptureChildRelation($capture, $plan);
                    return $scope;
                },
                canonicalDependencies: $canonicalDependencies,
                knowledgeRepository: $claims,
                knowledgeRepairPreview: $knowledgeRepairPreview,
            );
            $articleReceipts = new WpdbArticleOperationReceiptRepository($wpdb);
            $categoryGateway = new CategoryGateway(new WpCategoryStore());
            $editorialPosts = new WpEditorialPostStore($articleEditorial);
            $canonicalPublicationContext = static function (\NHK\Core\Domain\Article\EditorialPostState $state, array $callerEvidence) use ($captureRepository, $articleResearch, $articlePreflightHandoff, $articleMedia): array {
                $capture = $captureRepository->findByArticleId($state->postId);
                if ($capture === null) return $callerEvidence;
                if ($capture->articleId !== $state->postId) throw new \RuntimeException('CAPTURE_ARTICLE_BINDING_UNAVAILABLE');
                $persistedSubject = is_array($capture->diagnostics['subjects'] ?? null) ? $capture->diagnostics['subjects'] : [];
                if ($persistedSubject === []) $persistedSubject = is_array($capture->context['subject_resolution'] ?? null) ? $capture->context['subject_resolution'] : [];
                $primary = is_array($persistedSubject['primary'] ?? null) ? $persistedSubject['primary'] : [];
                if (trim((string) ($primary['id'] ?? '')) === '' || trim((string) ($primary['type'] ?? '')) === '') throw new \RuntimeException('CAPTURE_SUBJECT_BINDING_UNAVAILABLE');
                $contentIntent = is_array($capture->context['content_intent'] ?? null) ? $capture->context['content_intent'] : [];
                $composition = is_array($capture->diagnostics['composition'] ?? null) ? $capture->diagnostics['composition'] : [];
                $subjectPacket = is_array($capture->context['subject_resolution_packet'] ?? null)
                    ? $capture->context['subject_resolution_packet']
                    : (is_array($capture->diagnostics['subject_resolution_packet'] ?? null) ? $capture->diagnostics['subject_resolution_packet'] : []);
                $captureMediaIds = array_values(array_unique(array_filter(array_map(static fn (mixed $asset): string => is_array($asset) ? trim((string) ($asset['media_id'] ?? '')) : '', $capture->assets))));
                $mediaReadback = $articleMedia->diagnoseForPost($state->postId, [
                    'subject_resolution_packet' => $subjectPacket,
                    'subject_resolution' => $persistedSubject,
                    'subject_ids' => [$primary['id']],
                    'capture_id' => $capture->captureId,
                    'capture_owned_media_ids' => $captureMediaIds,
                    'content_intent' => $contentIntent,
                ])->toArray();
                $research = $articleResearch->research($state->title, $primary, [
                    'post_id' => $state->postId,
                    'title' => $state->title,
                    'excerpt' => $state->excerpt,
                    'body' => $state->content,
                    'content_intent' => $contentIntent,
                    'subject_resolution_packet' => $subjectPacket,
                    'claim_trace' => is_array($composition['claim_trace'] ?? null) ? $composition['claim_trace'] : [],
                ]);
                $semanticWriteBack = is_array($capture->diagnostics['semantic_write_back'] ?? null) ? $capture->diagnostics['semantic_write_back'] : [];
                $canonical = $articlePreflightHandoff->build(
                    $research,
                    $mediaReadback,
                    $semanticWriteBack,
                    $state->snapshot() + ['content_intent' => strtoupper(trim((string) ($contentIntent['intent'] ?? 'TEXT_ARTICLE')))],
                );
                // Article identity is already durable. Publication checks for a
                // new public collision; it must not re-run creation-time intent.
                $canonical['duplicate_intent_handled'] = true;
                $canonical['canonical_publication_context'] = [
                    'capture_id' => $capture->captureId,
                    'capture_revision' => $capture->revision,
                    'subject_binding' => $primary,
                    'article_state_token' => $state->token,
                ];
                return array_replace($callerEvidence, $canonical);
            };
            $ownerPublication = new OwnerPublicationApplicationService($editorialPosts, new WpdbOwnerPublicationDecisionRepository($wpdb), static fn (PublicationPrincipal $principal): bool => current_user_can('nhk_ingest_articles') && current_user_can('publish_posts'), null, $articleReceipts, $canonicalPublicationContext);
            $mcpGovernance->setPublicationBoundary(static function (\NHK\Core\Domain\Governance\Proposal $proposal, array $applied) use ($ownerPublication): array {
                $payload = $proposal->payload;
                $postId = (int) ($payload['post_id'] ?? 0);
                $token = trim((string) ($payload['expected_state_token'] ?? ''));
                $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
                if ($postId < 1 || $token === '') return ['frontend_available' => false, 'diagnostics' => ['ARTICLE_PUBLICATION_INPUT_INVALID']];
                $result = $ownerPublication->request($postId, $token, $evidence, $proposal->idempotencyKey . ':article', new PublicationPrincipal('0', 'governance', $proposal->id));
                $published = ($result['outcome'] ?? '') === 'PASS' && (($result['post']['status'] ?? '') === 'publish');
                return ['frontend_available' => $published, 'public_url' => (string) ($result['public_url'] ?? ''), 'publication' => $result, 'canonical_readback' => $applied['canonical_readback'] ?? null];
            });
            $draftGateway = new EditorialDraftGateway($editorialPosts, $articleReceipts, $ownerPublication);
            // One generic recovery boundary for existing Capture-owned Articles.
            // The orchestrator plans and bounds work; existing owner adapters
            // remain responsible for every durable mutation.
            add_filter('nhk_v3_article_reconciliation_orchestrator', static function (mixed $current) use ($articleEditorial, $captureRepository, $articleMedia, $canonicalPublicationContext, $draftGateway, $editorialPosts, $graphService, $articleCoordinator, $authority, $types): mixed {
                if ($current instanceof \NHK\Core\Application\Article\ArticleReconciliationOrchestrator) return $current;
                return new \NHK\Core\Application\Article\ArticleReconciliationOrchestrator(
                    static function (array $input) use ($articleEditorial, $captureRepository, $authority, $types): array {
                        $postId = (int) ($input['post_id'] ?? 0);
                        $state = $articleEditorial->read($postId);
                        if ($state === null) throw new \RuntimeException('WP_POST_UNAVAILABLE');
                        $capture = $captureRepository->findByArticleId($postId);
                        $packet = is_array($input['subject_resolution_packet'] ?? null)
                            ? $input['subject_resolution_packet']
                            : (is_object($capture) && is_array($capture->context['subject_resolution_packet'] ?? null) ? $capture->context['subject_resolution_packet'] : []);
                        if ($packet === []) $packet = ['status' => 'unresolved', 'diagnostics' => ['SUBJECT_PACKET_REQUIRED_FOR_ARTICLE_RECONCILIATION']];
                        $desiredMedia = is_array($input['article_media'] ?? null) ? $input['article_media'] : [];
                        $intent = strtoupper((string) (($capture?->context['content_intent']['intent'] ?? $input['intent'] ?? '')));
                        $selected = is_array($desiredMedia['selected'] ?? null) ? $desiredMedia['selected'] : [];
                        if ($intent === 'IMAGE_ARTICLE' && isset($selected['media_id'])) {
                            $desiredMedia['selected'] = [
                                'featured_primary' => $selected + ['role' => 'featured_primary'],
                                'inline_primary' => $selected + ['role' => 'inline_primary'],
                            ];
                        }
                        return ['post_id' => $postId, 'state' => $state, 'capture' => $capture, 'slug' => $state->slug, 'permalink' => $state->permalink, 'subject_resolution_packet' => $packet, 'desired_media' => $desiredMedia, 'media_context' => is_array($input['media_context'] ?? null) ? $input['media_context'] : []];
                    },
                    static fn (array $state): array => is_object($state['capture'] ?? null) && is_array($state['capture']->context['content_intent'] ?? null) ? $state['capture']->context['content_intent'] : ['intent' => 'TEXT_ARTICLE'],
                    static function (array $state): array {
                        return is_array($state['subject_resolution_packet'] ?? null) ? $state['subject_resolution_packet'] : [];
                    },
                    static function (array $state) use ($canonicalPublicationContext, $articleMedia, $graphService): array {
                        $owner = $state['state'] ?? null;
                        if (!$owner instanceof \NHK\Core\Domain\Article\EditorialPostState) return ['diagnostics' => ['WP_POST_UNAVAILABLE']];
                        $evidence = $canonicalPublicationContext($owner, []);
                        $mediaContext = is_array($state['media_context'] ?? null) ? $state['media_context'] : [];
                        if (($state['desired_media'] ?? []) !== []) $mediaContext['article_media'] = $state['desired_media'];
                        if (($state['subject_resolution_packet'] ?? []) !== []) $mediaContext['subject_resolution_packet'] = $state['subject_resolution_packet'];
                        $media = $articleMedia->diagnoseForPost($owner->postId, $mediaContext)->toArray();
                        $evidence['media_snapshot'] = $media;
                        $gate = (new \NHK\Core\Application\Article\ArticlePublicationGate())->check($owner, $evidence, $owner->token);
                        $mediaDiagnostics = array_values(array_filter(array_map(static fn (array $item): string => (string) ($item['code'] ?? ''), (array) ($media['diagnostics'] ?? [])), static fn (string $code): bool => $code !== ''));
                        $relationDiagnostics = [];
                        $aboutEdges = [];
                        $packet = is_array($state['subject_packet'] ?? null) ? $state['subject_packet'] : [];
                        $targetId = trim((string) ($packet['canonical_subject_id'] ?? $packet['id'] ?? ''));
                        try {
                            $aboutEdges = array_values(array_filter((array) ($graphService->findOutgoing(new \NHK\Core\Domain\Graph\NodeReference('wp_post', $owner->endpointKey), 'about', 0, 200, false)['items'] ?? []), static fn (mixed $edge): bool => $edge instanceof \NHK\Core\Domain\Graph\GraphEdge && $edge->isActive()));
                            $matching = array_values(array_filter($aboutEdges, static fn ($edge): bool => $edge->target->reference->endpoint_key === $targetId));
                            if ($targetId !== '' && count($matching) !== 1) $relationDiagnostics[] = 'ARTICLE_ABOUT_RELATION_MISMATCH';
                        } catch (\Throwable) {
                            $relationDiagnostics[] = 'SEMANTIC_READBACK_UNVERIFIED';
                        }
                        return ['diagnostics' => array_values(array_unique(array_merge($gate->blockers, $mediaDiagnostics, $relationDiagnostics))), 'evidence' => $evidence, 'state_token' => $owner->token, 'media' => $media, 'about_edges' => $aboutEdges];
                    },
                    static function (array $state, array $actions) use ($articleMedia, $editorialPosts, $captureRepository, $articleCoordinator): array {
                        $postId = (int) ($state['post_id'] ?? 0);
                        $capture = $state['capture'] ?? null;
                        $packet = is_array($state['subject_packet'] ?? null) ? $state['subject_packet'] : [];
                        foreach ($actions as $action) {
                            if ($action->action === 'SUPERSEDE_SUBJECT_PACKET' && $capture instanceof \NHK\Core\Domain\Capture\CaptureRecord && $packet !== []) {
                                $old = is_array($capture->context['subject_resolution_packet'] ?? null) ? $capture->context['subject_resolution_packet'] : [];
                                $history = is_array($capture->context['subject_resolution_packet_history'] ?? null) ? $capture->context['subject_resolution_packet_history'] : [];
                                if ($old !== [] && $history === []) $history[] = $old;
                                $context = $capture->context;
                                $context['subject_resolution_packet'] = $packet;
                                $context['subject_resolution_packet_history'] = $history;
                                $diagnostics = $capture->diagnostics;
                                $diagnostics['subject_resolution_packet'] = $packet;
                                $diagnostics['subjects'] = ['status' => 'resolved', 'primary' => ['id' => (string) ($packet['canonical_subject_id'] ?? $packet['id'] ?? ''), 'type' => (string) ($packet['entity_type'] ?? $packet['type'] ?? ''), 'stable_key' => (string) ($packet['stable_key'] ?? ''), 'name' => (string) ($packet['canonical_name'] ?? $packet['name'] ?? ''), 'revision' => (int) ($packet['revision'] ?? 1)]];
                                $diagnostics['subject_packet_supersession'] = ['previous' => $old, 'current' => $packet];
                                $capture = $captureRepository->save(new \NHK\Core\Domain\Capture\CaptureRecord($capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint, $capture->stage, $capture->status, $capture->articleId, $capture->articleStateToken, $capture->assets, $context, $diagnostics, $capture->phaseReceipts, $capture->revision + 1, $capture->createdAt, gmdate('Y-m-d H:i:s.u')));
                            }
                            if ($action->action === 'CONVERGE_PRIMARY_ABOUT' && $capture instanceof \NHK\Core\Domain\Capture\CaptureRecord) {
                                $sourceKey = (string) ($state['state']->endpointKey ?? '');
                                $freshOwner = $editorialPosts->read($postId);
                                $freshToken = $freshOwner?->token ?? (string) ($state['state_token'] ?? $state['state']->token);
                                $targetId = trim((string) ($packet['canonical_subject_id'] ?? $packet['id'] ?? ''));
                                $targetType = trim((string) ($packet['entity_type'] ?? $packet['type'] ?? ''));
                                $commands = [];
                                foreach ((array) ($state['inspection']['about_edges'] ?? []) as $edge) {
                                    if (!$edge instanceof \NHK\Core\Domain\Graph\GraphEdge || !$edge->isActive()) continue;
                                    if ($edge->target->reference->endpoint_key === $targetId) continue;
                                    $commands[] = ['slot' => 'retire-about-' . $edge->edge_uuid, 'operation' => 'relation_retire', 'entity_type' => 'relation', 'subject_id' => $edge->edge_uuid, 'target_uuid' => $edge->edge_uuid, 'expected_revision' => $edge->revision, 'payload' => ['source_type' => 'wp_post', 'source_key' => $sourceKey, 'target_type' => $edge->target->reference->endpoint_type, 'target_key' => $edge->target->reference->endpoint_key, 'predicate' => 'about', 'capture_id' => $capture->captureId]];
                                }
                                $create = $targetId !== '' && $targetType !== '' ? ['slot' => 'create-about-' . $targetId, 'operation' => 'relation_create', 'entity_type' => 'relation', 'subject_id' => $sourceKey, 'target_uuid' => $targetId, 'expected_revision' => 1, 'payload' => ['source_type' => 'wp_post', 'source_key' => $sourceKey, 'target_type' => $targetType, 'target_key' => $targetId, 'predicate' => 'about', 'capture_id' => $capture->captureId, 'provenance' => 'CURRENT_EXPLICIT_SUBJECT_RECONCILIATION', 'reason' => 'Current exact canonical subject supersedes stale Article binding.']]: null;
                                $base = ['intent' => 'reconcile', 'capture_id' => $capture->captureId, 'target_wp_post' => ['endpoint_type' => 'wp_post', 'endpoint_key' => $sourceKey], 'expected_editorial_state' => ['state_token' => $freshToken]];
                                if ($commands !== []) {
                                    $retireResult = $articleCoordinator->execute($base + ['idempotency_key' => $capture->captureId . ':article-about-retire:' . hash('sha256', json_encode($commands, JSON_THROW_ON_ERROR)), 'semantic_bundle' => ['commands' => $commands]]);
                                    if (($retireResult->outcome ?? null) === \NHK\Core\Domain\Article\ArticleIngestOutcome::COMPLETED) {
                                        $freshEdges = $graphService->findOutgoing(new \NHK\Core\Domain\Graph\NodeReference('wp_post', $sourceKey), 'about', 0, 200, false)['items'] ?? [];
                                        $commands = [];
                                        foreach ((array) $freshEdges as $edge) if ($edge instanceof \NHK\Core\Domain\Graph\GraphEdge && $edge->isActive() && $edge->target->reference->endpoint_key !== $targetId) $commands[] = ['slot' => 'retire-about-' . $edge->edge_uuid, 'operation' => 'relation_retire', 'entity_type' => 'relation', 'subject_id' => $edge->edge_uuid, 'target_uuid' => $edge->edge_uuid, 'expected_revision' => $edge->revision, 'payload' => ['source_type' => 'wp_post', 'source_key' => $sourceKey, 'target_type' => $edge->target->reference->endpoint_type, 'target_key' => $edge->target->reference->endpoint_key, 'predicate' => 'about', 'capture_id' => $capture->captureId]];
                                    }
                                }
                                if ($create !== null && $commands === []) $articleCoordinator->execute($base + ['idempotency_key' => $capture->captureId . ':article-about-create:' . hash('sha256', json_encode($packet, JSON_THROW_ON_ERROR)), 'semantic_bundle' => ['commands' => [$create]]]);
                            }
                            if ($action->owner === 'media') {
                                $desired = is_array($state['desired_media']['selected'] ?? null) ? $state['desired_media']['selected'] : [];
                                if (isset($desired['media_id'])) $desired = [(string) ($desired['role'] ?? 'featured_primary') => $desired];
                                $mediaContext = ['subject' => (string) (($state['state']->title ?? '')), 'capture_id' => $capture instanceof \NHK\Core\Domain\Capture\CaptureRecord ? $capture->captureId : '', 'capture_has_physical_assets' => true, 'force_inline_reconcile' => true, 'allow_historical_reuse' => false, 'allow_scoped_reuse' => false, 'content_intent' => is_object($capture) && is_array($capture->context['content_intent'] ?? null) ? $capture->context['content_intent'] : [], 'subject_resolution_packet' => $packet];
                                if (($mediaContext['content_intent']['intent'] ?? '') === 'IMAGE_ARTICLE' && count($desired) === 1) $mediaContext['single_real_image_exception'] = true;
                                $articleMedia->ensureForPost($postId, $mediaContext, $desired);
                            }
                            if ($action->action === 'ALLOCATE_SLUG' && isset($state['state'])) $editorialPosts->update($postId, ['post_name' => sanitize_title((string) $state['state']->title)]);
                        }
                        $fresh = $editorialPosts->read($postId);
                        $nextCapture = $capture instanceof \NHK\Core\Domain\Capture\CaptureRecord ? $captureRepository->findById($capture->captureId) : null;
                        return $fresh === null ? [] : ['state' => $fresh, 'capture' => $nextCapture, 'subject_resolution_packet' => $packet, 'slug' => $fresh->slug, 'permalink' => $fresh->permalink];
                    },
                    static function (array $state) use ($draftGateway): array {
                        $owner = $state['state'] ?? null;
                        $evidence = is_array($state['inspection']['evidence'] ?? null) ? $state['inspection']['evidence'] : [];
                        if (!$owner instanceof \NHK\Core\Domain\Article\EditorialPostState) return ['outcome' => 'SYSTEM_BLOCKED'];
                        return $draftGateway->reviewPublication($owner->postId, $owner->token, $evidence, 'article-reconciliation-review:' . $owner->postId);
                    },
                    static function (array $state) use ($draftGateway): array {
                        $owner = $state['state'] ?? null;
                        if (!$owner instanceof \NHK\Core\Domain\Article\EditorialPostState) return ['status' => 'blocked'];
                        return $draftGateway->publish($owner->postId, $owner->token, (array) ($state['inspection']['evidence'] ?? []), 'article-reconciliation-publish:' . $owner->postId);
                    },
                    static function (array $state) use ($articleEditorial): array {
                        $owner = $articleEditorial->read((int) ($state['post_id'] ?? 0));
                        if (($state['publish_requested'] ?? false) !== true) return $owner !== null ? ['status' => 'verified', 'state_token' => $owner->token] : ['status' => 'blocked'];
                        return $owner !== null && $owner->status === 'publish' && $owner->permalink !== '' ? ['status' => 'verified', 'permalink' => $owner->permalink] : ['status' => 'blocked'];
                    },
                );
            }, 10, 1);
            $semanticWritePolicy = new SemanticWritePolicyResolver();
            $documentation = new McpDocumentationRegistry(null, null, $semanticWritePolicy);
            $authorityPolicyStorage = new \NHK\Core\Infrastructure\Governance\WpOptionConversationalAuthorityPolicyStorage();
            // Bound by reference because the same Capture must first finish
            // Authority read-back and then resume the already-created
            // editorial draft through the canonical coordinator.
            $capture = null;
            $authorityCapture = new \NHK\Core\Application\Capture\AuthorityCaptureService(
                $captureRepository,
                static function (array $input, \NHK\Core\Domain\Capture\CaptureRecord $capture) use ($authority, $types, $documentation, $automationResolver, $authorityPolicyStorage, $explicitRelationIntentPlanner, $relationshipRead): array {
                    $checkpoint = $documentation->bootstrap();
                    $generic = \NHK\Core\Domain\Governance\AutomationMode::REVIEW_REQUIRED;
                    foreach (['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'] as $type) if (in_array($automationResolver->resolve($type), [\NHK\Core\Domain\Governance\AutomationMode::AUTO_APPROVE, \NHK\Core\Domain\Governance\AutomationMode::AUTO_PUBLISH], true)) $generic = \NHK\Core\Domain\Governance\AutomationMode::AUTO_APPROVE;
                    $effective = \NHK\Core\Application\Governance\ConversationalAuthorityPolicyResolver::effective($generic, $authorityPolicyStorage->read());
                    $predicateRegistry = new \NHK\Core\Domain\Graph\PredicateRegistry();
                    $contract = array_merge($checkpoint, is_array($input['documentation_checkpoint'] ?? null) ? $input['documentation_checkpoint'] : [], [
                        'stable_key_policy_version' => \NHK\Core\Application\Authority\CanonicalAuthorityStableKeyPolicy::VERSION,
                        'authority_registry_version' => \NHK\Core\Domain\Authority\CanonicalEntityTypeCatalog::VERSION,
                        'predicate_registry_version' => \NHK\Core\Domain\Graph\PredicateRegistry::VERSION,
                        'conversational_authority_policy_version' => \NHK\Core\Domain\Governance\ConversationalAuthorityPolicy::VERSION,
                        'generic_governance_policy_version' => '1.0.0',
                        'effective_governance_policy' => $effective->value,
                        'authority_registry_fingerprint' => hash('sha256', json_encode(array_map(static fn ($definition): array => [$definition->type, $definition->schemaVersion, $definition->allowedFields, $definition->requiredFields], $types->all()), JSON_THROW_ON_ERROR)),
                        'predicate_registry_fingerprint' => hash('sha256', json_encode(array_map(static fn ($definition): array => [$definition->key, $definition->allowed_source_types, $definition->allowed_target_types, $definition->outbound_cardinality, $definition->inbound_cardinality], $predicateRegistry->all()), JSON_THROW_ON_ERROR)),
                        'semantic_contract_version' => 'authority-graph-2026-09-11',
                    ]);
                    $plan = (new \NHK\Core\Application\Authority\AuthorityIntentPlanner($authority, $types, relationIntents: $explicitRelationIntentPlanner))->plan($input, ['capture_id' => $capture->captureId, 'capture_revision' => (int) ($capture->context['planning_revision'] ?? $capture->revision), 'contract' => $contract]);
                    $operations = $input['relationship_operations'] ?? [];
                    if (is_array($operations) && array_is_list($operations)) {
                        foreach ($operations as $operationInput) {
                            if (!is_array($operationInput)) { $plan['blockers'][] = ['code' => 'RELATION_OPERATION_MALFORMED']; continue; }
                            $preview = $relationshipRead->preview($operationInput);
                            $operation = strtoupper((string) ($operationInput['operation'] ?? ''));
                            $fingerprint = (string) ($preview['preview_fingerprint'] ?? '');
                            if (!($preview['safe_to_apply'] ?? false)) { $plan['blockers'] = array_merge($plan['blockers'], array_map(static fn ($code): array => ['code' => (string) $code], (array) ($preview['blockers'] ?? ['RELATION_OPERATION_BLOCKED']))); continue; }
                            $transition = (array) ($preview['planned_transition'] ?? []);
                            $candidate = [
                                'candidate_id' => 'relationship-operation-' . hash('sha256', json_encode([$operationInput, $fingerprint], JSON_THROW_ON_ERROR)),
                                'entity_type' => 'relation', 'operation' => $operation, 'action' => $operation === 'ADD' ? 'CREATE' : $operation,
                                'source_type' => (string) ($preview['normalized_source']['type'] ?? ''), 'source_uuid' => (string) ($preview['normalized_source']['id'] ?? ''),
                                'predicate' => (string) ($operationInput['predicate'] ?? ''), 'target_type' => (string) ($preview['normalized_target']['type'] ?? ''), 'target_uuid' => (string) ($preview['normalized_target']['id'] ?? ''),
                                'current_relation_id' => (string) ($operationInput['current_relation_id'] ?? ''), 'expected_edge_revision' => (int) ($operationInput['expected_edge_revision'] ?? 0),
                                'source_revision' => (int) ($preview['revision_state']['source_revision'] ?? 0), 'target_revision' => (int) ($preview['revision_state']['target_revision'] ?? 0),
                                'provenance' => (string) ($operationInput['provenance'] ?? ''), 'evidence_refs' => (array) ($operationInput['evidence_refs'] ?? []), 'reason' => (string) ($operationInput['reason'] ?? ''),
                                'preview_fingerprint' => $fingerprint, 'registry_hash' => (string) ($operationInput['registry_hash'] ?? ''), 'dependencies' => [], 'transition' => $transition,
                            ];
                            if ($operation === 'ADD' && (($transition[0]['action'] ?? '') === 'RELATION_NO_OP')) $plan['relation_reuse'][] = $candidate + ['action' => 'REUSE', 'canonical_id' => $transition[0]['relation_id'] ?? '', 'revision' => $transition[0]['current_revision'] ?? 0, 'idempotent' => true];
                            else $plan['relation_candidates'][] = $candidate;
                        }
                        $plan['create_relations'] = array_values($plan['relation_candidates']);
                    }
                    return $plan;
                },
                static function (array $input, \NHK\Core\Domain\Capture\CaptureRecord $capture) use ($draftGateway): array {
                    return $draftGateway->create(['capture_id' => $capture->captureId, 'idempotency_key' => $capture->captureId . ':article', 'title' => (string) ($input['title'] ?? ''), 'content' => (string) ($input['text'] ?? $input['content'] ?? ''), 'excerpt' => (string) ($input['excerpt'] ?? '')]);
                },
                static function (\NHK\Core\Domain\Capture\CaptureRecord $capture, array $plan, array $ids, ?array $stagingAcceptance = null) use ($mcpGovernance, $automationResolver, $authorityPolicyStorage, $semanticWritePolicy): array {
                    $generic = \NHK\Core\Domain\Governance\AutomationMode::REVIEW_REQUIRED;
                    foreach (['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'] as $type) {
                        if (in_array($automationResolver->resolve($type), [\NHK\Core\Domain\Governance\AutomationMode::AUTO_APPROVE, \NHK\Core\Domain\Governance\AutomationMode::AUTO_PUBLISH], true)) $generic = \NHK\Core\Domain\Governance\AutomationMode::AUTO_APPROVE;
                    }
                    $effective = \NHK\Core\Application\Governance\ConversationalAuthorityPolicyResolver::effective($generic, $authorityPolicyStorage->read());
                    return (new \NHK\Core\Application\Governance\GovernedAuthorityPlanExecutor($mcpGovernance))->execute($plan, (string) ($plan['plan_fingerprint'] ?? ''), (string) ($plan['plan_fingerprint'] ?? ''), $ids, $effective, function_exists('get_current_user_id') ? (string) get_current_user_id() : '0', ['capture_id' => $capture->captureId, 'policy_mode' => $semanticWritePolicy->resolve()->value, 'approval_mode' => $effective->value], $stagingAcceptance);
                },
                static function (\NHK\Core\Domain\Capture\CaptureRecord $record, array $result) use (&$capture): array {
                    if (!$capture instanceof \NHK\Core\Application\Capture\EditorialCaptureCoordinator) return ['status' => 'RECONCILIATION_PENDING', 'code' => 'EDITORIAL_RECONCILIATION_UNAVAILABLE', 'capture_id' => $record->captureId];
                    $continued = $capture->continueWithAddendum($record, [
                        'text' => '',
                        'subject_hints' => (array) ($record->context['subject_hints'] ?? []),
                        'observations' => (array) ($record->context['observations'] ?? []),
                        'metadata' => (array) ($record->context['metadata'] ?? []),
                    ]);
                    return ['status' => 'RECONCILED', 'capture_id' => $continued->captureId, 'article_id' => $continued->articleId, 'capture_status' => $continued->status, 'capture_stage' => $continued->stage, 'diagnostics' => $continued->diagnostics, 'capture_record' => $continued];
                },
                null,
                static function (\NHK\Core\Domain\Capture\CaptureRecord $capture, array $plan, array $ids) use ($stagingScopeVerifier): array {
                    return $stagingScopeVerifier->issueForAuthorityPlan($capture, $plan, $ids);
                }
            );
            $captureAddendumRepository = new WpdbCaptureAddendumRepository($wpdb);
            $captureAuthorityResolver = new \NHK\Core\Application\Semantic\CanonicalAuthoritySubjectResolver($authority, $types);
            $captureSubjectResolver = new SubjectResolutionService(
                $captureAuthorityResolver,
                new \NHK\Core\Application\Semantic\CanonicalSubjectStructuralContextReader(new StructuralContextQuery($graphService, $authority), $authority),
                [$captureAuthorityResolver, 'resolveComposite']
            );
            $clockTypeMembershipReader = new GraphClockTypeCanonicalMembershipReader($graphService, $authority);
            $clockTypeShadowClassifier = new ClockTypeShadowClassifier($authority, new \NHK\Core\Application\Entity\EntityProfileResolver(), $clockTypeMembershipReader);
            $captureNeighborhood = $mcpNeighborhood;
            $captureClaims = new ClaimRetrievalEngine(
                static function (array $subject) use ($captureNeighborhood): array {
                    $type = (string) ($subject['type'] ?? '');
                    $profile = in_array($type, ['brand', 'model', 'variant', 'classification', 'specimen'], true) ? $type : 'variant';
                    return $captureNeighborhood->query(new \NHK\Core\Domain\Graph\NodeReference($type, (string) ($subject['id'] ?? '')), $profile, 2, 50);
                },
                static function (array $subject, array $neighborhood) use ($claims, $evidence): array {
                    $allowed = [(string) ($subject['id'] ?? '')];
                    foreach ((array) ($neighborhood['items'] ?? []) as $item) if (is_array($item) && isset($item['target_entity_id'])) $allowed[] = (string) $item['target_entity_id'];
                    $rows = [];
                    foreach ($claims->list() as $claim) {
                        $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
                        $claimSubject = (string) ($metadata['subject_id'] ?? $claim->provenance['subject_id'] ?? '');
                        if ($claimSubject === '' || !in_array($claimSubject, $allowed, true)) continue;
                        $support = false;
                        foreach ($evidence->listByClaim($claim->canonicalId) as $citation) if ($citation->active && $citation->relation === 'supports') { $support = true; break; }
                        $claimSubjectType = strtolower(trim((string) ($metadata['subject_type'] ?? '')));
                        $claimPath = [];
                        if ($claimSubject !== (string) ($subject['id'] ?? '')) foreach ((array) ($neighborhood['items'] ?? []) as $item) {
                            if (!is_array($item) || (string) ($item['target_entity_id'] ?? '') !== $claimSubject) continue;
                            if (is_array($item['best_path'] ?? null)) { $claimPath = $item['best_path']; break; }
                        }
                        $rows[] = ['id' => $claim->canonicalId, 'revision' => $claim->revision, 'text' => $claim->claimText, 'subject_id' => $claimSubject, 'subject_type' => $claimSubjectType, 'relation_path' => $claimPath, 'scope' => (string) ($metadata['scope'] ?? $claim->claimType), 'provenance' => (string) ($metadata['provenance'] ?? 'CATALOG_SUPPORTED'), 'evidence_status' => $support ? 'SUPPORTED_WITHIN_SCOPE' : 'INSUFFICIENT_EVIDENCE', 'relevance' => $claimSubject === (string) ($subject['id'] ?? '') ? 1.0 : 0.7];
                    }
                    return $rows;
                },
                predicates: $predicates,
            );
            $youtubeConfiguration = new \NHK\Core\Application\Video\YouTubeApiConfiguration();
            $youtubeClient = static fn (object $identity): array => (new YouTubeDataApiClient(null, null, $youtubeConfiguration))->fetch($identity);
            $sharedEnrichment = new SharedEnrichmentBoundary(
                new EditorialClaimRetrievalService($captureClaims),
                new EditorialKnowledgeSelector(),
                decomposer: new \NHK\Core\Application\Semantic\SemanticNeedDecomposer(new TextInputInterpreter(), new \NHK\Core\Application\Semantic\SemanticNeedVocabulary()),
                relations: static function (array $request) use ($videoRelationCandidates): array {
                    $videoId = trim((string) ($request['owner_id'] ?? ''));
                    $relations = array_values(array_filter((array) ($request['relations'] ?? []), 'is_array'));
                    if ($videoId === '' || $relations === []) return [];
                    return array_values(array_map(static fn (\NHK\Core\Domain\Video\VideoRelationCandidate $candidate): array => $candidate->toProposalPayload(), $videoRelationCandidates->plan($videoId, $relations, true)));
                },
            );
            $videoEditorialAdapter = VideoEditorialAdapter::fromEngine($captureClaims, $sharedEnrichment);
            $videoEditorialResume = new \NHK\Core\Application\Video\VideoEditorialResumePlanner($videos, new VideoEditorialGenerator(), new VideoSeoProjection(), null, $videoKnowledgeEnrichment, $videoEditorialAdapter);
            $captureGovernance->setVideoEditorialResume($videoEditorialResume);
            $videoIntake = new VideoIntakeService(new YouTubeSourceAdapter($youtubeClient), $videos, new VideoHubClassifier(), $videoRelationCandidates, new VideoEditorialGenerator(), new VideoCompletenessPolicy(), new VideoSeoProjection(), new VideoInternalSemanticResearcher($authority, $types), $videoKnowledgeEnrichment, $videoEditorialAdapter);
            $videoSourceRefresh = new VideoSourceRefreshCommand($videos, $governance, $youtubeClient, $stagingScopeVerifier);
            $videoFrontendReader = new MediaVideoPageQuery($media, $assets, $usages, $videos, new MigrationStatus(), null, null, null, $claims, $evidence, $sources);
            $videoPublicationVerifier = new CaptureVideoPublicationVerifier(
                $videos,
                $publicIdentityService,
                $publicIdentityRepository,
                $canonicalDependencies,
                static function (string $videoId, string $targetType, string $targetId) use ($graphService): bool {
                    try {
                        $edge = $graphService->findEdge(
                            new \NHK\Core\Domain\Graph\NodeReference('video', $videoId),
                            'about',
                            new \NHK\Core\Domain\Graph\NodeReference($targetType, $targetId),
                        );
                        return $edge !== null && $edge->isActive();
                    } catch (\Throwable) {
                        throw new \RuntimeException('VIDEO_ABOUT_RELATION_READBACK_UNAVAILABLE');
                    }
                },
                static function (string $videoId, string $path) use ($videoFrontendReader, $videos): array {
                    if (!preg_match('#^/video/([^/]+)/$#', $path, $matches)) return ['public_eligible' => false, 'frontend_verified' => false, 'blockers' => ['VIDEO_FRONTEND_READBACK_UNAVAILABLE']];
                    $route = $videoFrontendReader->videoBySlug(rawurldecode((string) $matches[1]));
                    $canonical = $videoFrontendReader->videoDetail($videoId);
                    $owner = $videos->findByCanonicalId($videoId);
                    $routeOwner = is_array($route) ? $videos->findByExternalReference('youtube', (string) ($route['external_id'] ?? '')) : null;
                    $archive = $videoFrontendReader->videoArchive(1, 100);
                    $archiveItems = array_values(array_filter((array) ($archive['items'] ?? []), static fn (mixed $item): bool => is_array($item) && (string) ($item['canonical_id'] ?? '') === $videoId));
                    if (!is_array($route) || !is_array($canonical) || !$owner instanceof \NHK\Core\Domain\Video\Video) {
                        return ['public_eligible' => false, 'frontend_verified' => false, 'blockers' => ['VIDEO_FRONTEND_READBACK_UNAVAILABLE']];
                    }
                    $editorial = is_array($owner->metadata['editorial'] ?? null) ? $owner->metadata['editorial'] : [];
                    $title = (string) ($editorial['title'] ?? $owner->title);
                    $externalId = $owner->externalVideoId;
                    $sameOwner = $routeOwner instanceof \NHK\Core\Domain\Video\Video
                        && $routeOwner->canonicalId === $videoId
                        && (string) ($canonical['public_url'] ?? '') === $path
                        && (string) ($route['public_url'] ?? '') === $path
                        && (string) ($route['external_id'] ?? '') === $externalId
                        && (string) ($canonical['external_id'] ?? '') === $externalId
                        && (string) ($route['title'] ?? '') === $title
                        && (string) ($canonical['title'] ?? '') === $title
                        && count($archiveItems) === 1
                        && (string) ($archiveItems[0]['public_url'] ?? '') === $path;
                    return $sameOwner
                        ? ['public_eligible' => true, 'frontend_verified' => true, 'projection_readback' => true, 'blockers' => []]
                        : ['public_eligible' => false, 'frontend_verified' => false, 'projection_readback' => true, 'blockers' => ['VIDEO_FRONTEND_PROJECTION_READBACK_MISSING']];
                },
            );
            $publicUrlMaintenance = (new \NHK\Core\Infrastructure\PublicIdentity\WordPressPublicUrlMaintenanceRuntime($wpdb, $authority, $types, $publicContexts, $videos, $media, $assets, $publicIdentityRepository))->service();
            $articleEditorialAdapter = ArticleEditorialAdapter::fromEngine($captureClaims, $sharedEnrichment);
            $captureCanonicalInventory = self::canonicalInventory($types, $authority, $media, $videos, $claims, $sources, $evidence);
            $contentPreparation = new ContentPreparationOrchestrator(
                $captureSubjectResolver,
                static function (array $context) use ($captureCanonicalInventory): array {
                    $page = $captureCanonicalInventory->inventory([], 200);
                    return ['status' => 'available', 'candidates' => $page->items, 'total' => $page->total];
                },
                static function (array $context) use ($captureGovernance): array {
                    $captureId = (string) ($context['capture_id'] ?? '');
                    $fingerprint = (string) ($context['preparation_fingerprint'] ?? '');
                    $request = is_array($context['request'] ?? null) ? $context['request'] : [];
                    $governed = $captureGovernance->execute(
                        $captureId,
                        $captureId . ':preparation:' . $fingerprint,
                        $context + ['preparation_request' => $request],
                        is_array($context['governance'] ?? null) ? $context['governance'] : [],
                    );
                    return is_array($governed) ? $governed : ['status' => 'REVIEW_REQUIRED'];
                },
            );
            $captureFeatureBindings = new \NHK\Core\Application\Media\CaptureFeatureBindingCoordinator($captureSubjectResolver, $mediaBindingService);
            $capture = new EditorialCaptureCoordinator(
                $captureRepository,
                static function (array $input) use ($imageIngest, $existingMediaResolver, $existingAttachmentUrlResolver, $wordpressAttachments, $mediaBindingService, $assets): array {
                    $trace = static function (string $stage, string $status, array $details = []): void {
                        $payload = array_merge(['stage' => $stage, 'status' => $status, 'at' => gmdate('c')], $details);
                        if (function_exists('do_action')) { try { do_action('nhk_v3_capture_stage_trace', $payload); } catch (\Throwable) { } }
                        if (function_exists('error_log')) {
                            $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            error_log('[nhk.capture.stage] ' . (is_string($encoded) ? $encoded : $stage));
                        }
                    };
                    $mediaIds = is_array($input['media_ids'] ?? null) ? array_values($input['media_ids']) : [];
                    $existingUrls = is_array($input['existing_media_urls'] ?? null) ? array_values($input['existing_media_urls']) : [];
                    if ($mediaIds !== [] || $existingUrls !== []) {
                        if ($mediaIds !== [] && $existingUrls !== []) throw new \InvalidArgumentException('CAPTURE_PHYSICAL_INPUT_AMBIGUOUS');
                        if (is_array($input['files'] ?? null) && $input['files'] !== []) throw new \InvalidArgumentException('CAPTURE_PHYSICAL_INPUT_AMBIGUOUS');
                        if ($existingUrls !== []) {
                            $seen = [];
                            $inputMetadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
                            $media = is_array($input['media'] ?? null) ? $input['media'] : (is_array($inputMetadata['media'] ?? null) ? $inputMetadata['media'] : []);
                            $items = [];
                            foreach ($existingUrls as $url) {
                                $url = trim((string) $url);
                                $attachmentId = 0;
                                $trace('URL_RESOLUTION', 'STARTED', ['capture_id' => (string) ($input['idempotency_key'] ?? '')]);
                                $relative = '';
                                $canonicalMedia = null;
                                if (is_array($input['media_bindings'] ?? null) && $input['media_bindings'] !== []) {
                                    try { $canonicalMedia = $mediaBindingService->resolveMediaReference(['url' => $url]); } catch (\Throwable) { $canonicalMedia = null; }
                                }
                                if ($canonicalMedia instanceof \NHK\Core\Domain\Media\Media) {
                                    foreach ($assets->listByMediaId($canonicalMedia->canonicalId) as $mappedAsset) {
                                        $candidateAttachmentId = (int) ($mappedAsset->metadata['wordpress_attachment_id'] ?? 0);
                                        if ($candidateAttachmentId > 0) { $attachmentId = $candidateAttachmentId; $relative = basename($url); break; }
                                    }
                                    if (($attachmentId ?? 0) < 1) throw new \InvalidArgumentException('MEDIA_BINDING_ATTACHMENT_MAPPING_NOT_FOUND');
                                } else {
                                    $relative = $existingAttachmentUrlResolver->relativeUploadPath($url);
                                }
                                $dedupeKey = $canonicalMedia instanceof \NHK\Core\Domain\Media\Media ? $canonicalMedia->canonicalId : $relative;
                                if (isset($seen[$dedupeKey])) throw new \InvalidArgumentException('EXISTING_MEDIA_URL_DUPLICATE');
                                $seen[$dedupeKey] = true;
                                $attachmentId = $attachmentId > 0 ? $attachmentId : $existingAttachmentUrlResolver->resolve($url);
                                $trace('URL_RESOLUTION', 'VERIFIED', ['attachment_id' => $attachmentId]);
                                $trace('ATTACHMENT_READBACK', 'STARTED', ['attachment_id' => $attachmentId]);
                                $readback = $wordpressAttachments->read($attachmentId);
                                if (!is_array($readback) || (int) ($readback['attachment_id'] ?? 0) !== $attachmentId) throw new \RuntimeException('EXISTING_MEDIA_ATTACHMENT_READBACK_FAILED');
                                $trace('ATTACHMENT_READBACK', 'VERIFIED', ['attachment_id' => $attachmentId, 'width' => (int) ($readback['width'] ?? 0), 'height' => (int) ($readback['height'] ?? 0)]);
                                $items[] = [
                                    'attachment_id' => $attachmentId,
                                    'source_url' => $url,
                                    'filename' => (string) ($readback['filename'] ?? basename($relative)),
                                    'original_filename' => (string) ($readback['original_filename'] ?? basename($relative)),
                                    'mime_type' => (string) ($readback['mime'] ?? ''),
                                    'byte_size' => (int) ($readback['filesize'] ?? 0),
                                    'width' => (int) ($readback['width'] ?? 0),
                                    'height' => (int) ($readback['height'] ?? 0),
                                    'attachment_readback_status' => 'verified',
                                    'upload_status' => 'REUSED',
                                    'reused' => true,
                                    'media_id' => $canonicalMedia instanceof \NHK\Core\Domain\Media\Media ? $canonicalMedia->canonicalId : '',
                                    'media_context' => $media,
                                    'sort_order' => count($items),
                                ];
                            }
                            $trace('PHYSICAL_INGEST', 'VERIFIED', ['items' => count($items)]);
                            return ['status' => 'verified', 'items' => $items, 'count' => count($items), 'reused' => true, 'physical_input' => 'existing_wordpress_media_url'];
                        }
                        $items = [];
                        foreach ($mediaIds as $ordinal => $mediaId) {
                            try {
                                $resolved = $existingMediaResolver->resolve([(string) $mediaId]);
                                $resolvedItems = array_values(array_filter($resolved, 'is_array'));
                                foreach ($resolvedItems as $item) {
                                    $item['sort_order'] = $ordinal;
                                    $items[] = $item;
                                }
                                $last = $items[array_key_last($items)] ?? [];
                                if ((string) ($last['media_id'] ?? '') !== (string) $mediaId) throw new \RuntimeException('MEDIA_READBACK_NOT_FOUND');
                            } catch (\Throwable $error) {
                                $items[] = [
                                    'media_id' => (string) $mediaId,
                                    'sort_order' => $ordinal,
                                    'upload_status' => 'FAILED_RETRYABLE',
                                    'attachment_readback_status' => 'failed',
                                    'disposition' => 'FAILED_RETRYABLE',
                                    'failure_code' => preg_replace('/[^A-Z0-9_:-]+/', '_', strtoupper(trim($error->getMessage()))) ?: 'MEDIA_READBACK_FAILED',
                                ];
                            }
                        }
                        foreach ((array) ($input['asset_inputs'] ?? []) as $assetInput) {
                            if (!is_array($assetInput)) continue;
                            $ordinal = (int) ($assetInput['ordinal'] ?? -1);
                            if ($ordinal < 0 || !isset($items[$ordinal])) continue;
                            $featureRequests = array_values(array_filter(array_map(static fn (mixed $value): string => trim((string) $value), (array) ($assetInput['feature_requests'] ?? [])), static fn (string $value): bool => $value !== ''));
                            $items[$ordinal]['media_context'] = array_replace(is_array($items[$ordinal]['media_context'] ?? null) ? $items[$ordinal]['media_context'] : [], array_filter(['title' => trim((string) ($assetInput['name'] ?? ''))], static fn (mixed $value): bool => $value !== ''));
                            $items[$ordinal]['capture_asset_input'] = ['name' => trim((string) ($assetInput['name'] ?? '')), 'feature_requests' => $featureRequests];
                            $items[$ordinal]['sort_order'] = $ordinal;
                        }
                        return ['status' => 'verified', 'items' => $items, 'count' => count($items), 'reused' => true];
                    }
                    $files = $input['files'] ?? [];
                    $manifest = ['status' => 'verified', 'items' => [], 'count' => 0];
                    if (is_array($files) && $files !== []) {
                        $manifest = $imageIngest->ingest(
                            (string) ($input['idempotency_key'] ?? '') . ':assets',
                            is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
                            $files,
                            is_array($input['items'] ?? null) ? $input['items'] : [],
                            (bool) ($input['_nhk_native_multipart'] ?? false),
                        );
                    }
                    $manifest['count'] = count((array) ($manifest['items'] ?? []));
                    return $manifest;
                },
                static function (array $input) use ($draftGateway): array { return $draftGateway->create($input); },
                new TextInputInterpreter(),
                $captureSubjectResolver,
                $captureClaims,
                static function (array $context) use ($mcpGovernance, $captureGovernance, $captureClaimReuse): array {
                    $interpretation = is_array($context['interpretation'] ?? null) ? $context['interpretation'] : [];
                    $primary = is_array($context['subject_resolution']['primary'] ?? null) ? $context['subject_resolution']['primary'] : [];
                    $subjectId = trim((string) ($primary['id'] ?? ''));
                    $defaultScope = (string) ($primary['type'] ?? '');
                    $retrievedClaims = is_array($context['retrieval']['selected_claims'] ?? null) ? $context['retrieval']['selected_claims'] : [];
                    $candidates = [];
                    $reusedClaims = [];
                    foreach ((array) ($interpretation['user_claim_candidates'] ?? []) as $candidate) {
                        if (!is_array($candidate)) continue;
                        $scope = (string) ($candidate['scope'] ?? ($defaultScope !== '' ? $defaultScope : 'variant'));
                        $candidateForReuse = ['text' => (string) ($candidate['text'] ?? ''), 'subject_id' => $subjectId, 'scope' => $scope];
                        $reused = $captureClaimReuse->find($candidateForReuse, $retrievedClaims);
                        if ($reused !== null) {
                            $reusedClaims[] = ['claim_id' => $reused['claim_id'] ?? '', 'claim_revision' => $reused['claim_revision'] ?? 1, 'subject_id' => $reused['subject_id'] ?? '', 'scope' => $reused['scope'] ?? ''];
                            continue;
                        }
                        $candidates[] = ['kind' => 'claim_candidate', 'text' => (string) ($candidate['text'] ?? ''), 'provenance' => (string) ($candidate['provenance'] ?? 'EXPLICIT_USER_KNOWLEDGE'), 'scope' => $scope, 'facet' => (string) ($candidate['facet'] ?? 'recognition'), 'attributed' => ($candidate['attributed'] ?? false) === true, 'review_required' => ($candidate['review_required'] ?? false) === true, 'subject_id' => $subjectId, 'subject_type' => (string) ($primary['type'] ?? '')];
                    }
                    $videoCandidates = [];
                    foreach ((array) ($context['assets'] ?? []) as $asset) {
                        if (!is_array($asset) || ($asset['kind'] ?? '') !== 'video' || !is_array($asset['video_proposal'] ?? null)) continue;
                        $videoCandidates[] = ['kind' => 'video_ingest_candidate', 'proposal' => $asset['video_proposal'], 'status' => 'REVIEW_REQUIRED'];
                    }
                    if (($context['existing_capture_continuation'] ?? false) === true) {
                        $continuation = $captureGovernance->execute((string) ($context['capture_id'] ?? ''), (string) ($context['continuation_idempotency_key'] ?? ''), $context, is_array($context['governance'] ?? null) ? $context['governance'] : []);
                        return $continuation + ['candidate_writes' => array_merge($candidates, $videoCandidates), 'reused_claims' => $reusedClaims, 'relation_hints' => (array) ($interpretation['relation_hints'] ?? []), 'subject_resolution' => $context['subject_resolution'] ?? [], 'governance_available' => $mcpGovernance instanceof McpGovernanceHandler];
                    }
                    // New submissions and existing-Capture continuations must
                    // share the same governed lifecycle. The old new-Capture
                    // branch returned a permanent review placeholder here,
                    // silently discarding the Admin automation policy.
                    $governanceResult = $captureGovernance->execute(
                        (string) ($context['capture_id'] ?? ''),
                        (string) ($context['capture_id'] ?? '') . ':semantic',
                        $context + ['retrieval' => $context['retrieval'] ?? []],
                        is_array($context['governance'] ?? null) ? $context['governance'] : [],
                    );
                    return $governanceResult + ['candidate_writes' => array_merge($candidates, $videoCandidates), 'reused_claims' => $reusedClaims, 'relation_hints' => (array) ($interpretation['relation_hints'] ?? []), 'subject_resolution' => $context['subject_resolution'] ?? [], 'governance_available' => $mcpGovernance instanceof McpGovernanceHandler];
                },
                new ArticleComposer(),
                static function (array $context) use ($articleMedia, $mediaService, $usages, $media, $assets, $mediaBindingService, $mediaCapabilities, $mcpGovernance, $wordpressAttachments, $stagingScopeVerifier): array {
                    $trace = static function (string $stage, string $status, array $details = []): void {
                        $payload = array_merge(['stage' => $stage, 'status' => $status, 'at' => gmdate('c')], $details);
                        if (function_exists('do_action')) { try { do_action('nhk_v3_capture_stage_trace', $payload); } catch (\Throwable) { } }
                        if (function_exists('error_log')) {
                            $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            error_log('[nhk.capture.stage] ' . (is_string($encoded) ? $encoded : $stage));
                        }
                    };
                    $assets = is_array($context['assets'] ?? null) ? $context['assets'] : [];
                    $mediaIds = array_values(array_filter(array_map(static fn (mixed $asset): string => is_array($asset) ? trim((string) ($asset['media_id'] ?? '')) : '', $assets)));
                    if ($mediaIds === []) {
                        foreach ($assets as $asset) {
                            $attachmentId = is_array($asset) ? (int) ($asset['attachment_id'] ?? 0) : 0;
                            if ($attachmentId < 1) continue;
                            $readback = $wordpressAttachments->read($attachmentId);
                            $mappedId = is_array($readback) ? trim((string) ($readback['media_id'] ?? '')) : '';
                            if ($mappedId !== '') $mediaIds[] = $mappedId;
                        }
                        $mediaIds = array_values(array_unique($mediaIds));
                    }
                    $bindingResults = [];
                    $articleBindings = is_array($context['article_media_bindings'] ?? null) ? $context['article_media_bindings'] : [];
                    if ($articleBindings !== [] && is_array($context['staging_acceptance'] ?? null)) {
                        foreach ($articleBindings as $binding) {
                            if (!is_array($binding)) throw new \RuntimeException('STAGING_SCOPE_BINDING_INVALID');
                            $mediaRef = is_array($binding['media_ref'] ?? null) ? $binding['media_ref'] : [];
                            $mediaId = trim((string) ($mediaRef['media_id'] ?? ''));
                            if ($mediaId === '' && isset($mediaRef['item_index'])) $mediaId = trim((string) (($assets[(int) $mediaRef['item_index']]['media_id'] ?? '')));
                            $target = is_array($binding['target'] ?? null) ? $binding['target'] : [];
                            $scopeRequest = [
                                'capture_id' => (string) ($context['capture']['capture_id'] ?? ''),
                                'capture_fingerprint' => (string) ($context['capture_fingerprint'] ?? ($context['capture']['request_fingerprint'] ?? '')),
                                'payload_fingerprint' => (string) (($context['staging_acceptance']['payload_fingerprint'] ?? '')),
                                'operation' => 'representative_bind',
                                'media' => ['id' => $mediaId], 'target' => $target,
                                // The staging packet authorizes the exact
                                // Media/Article binding family; Article slot
                                // roles are applied by ArticleMediaCoordinator.
                                'role' => 'representative',
                                'selection_source' => (string) ($binding['selection_source'] ?? 'USER_EXPLICIT'),
                                'selection_policy' => (string) ($binding['selection_policy'] ?? 'PINNED'),
                            ];
                            if (!$stagingScopeVerifier->verifyBindingRequest((array) $context['staging_acceptance'], $scopeRequest)) throw new \RuntimeException('STAGING_SCOPE_NOT_APPROVED');
                        }
                    }
                    $typedBindings = is_array($context['media_bindings'] ?? null) ? $context['media_bindings'] : [];
                    if ($typedBindings !== []) {
                        $bindingContext = [
                            'capture_id' => (string) ($context['capture']['capture_id'] ?? ''),
                            'capture_fingerprint' => (string) ($context['capture_fingerprint'] ?? ($context['capture']['request_fingerprint'] ?? '')),
                            'staging_acceptance' => is_array($context['staging_acceptance'] ?? null) ? $context['staging_acceptance'] : null,
                        ];
                        if (isset($context['payload_fingerprint'])) $bindingContext['payload_fingerprint'] = (string) $context['payload_fingerprint'];
                        $bindingBatch = $mediaBindingService->bindMany($typedBindings, (string) ($context['capture']['capture_id'] ?? '') . ':media-binding', $assets, $bindingContext);
                        $bindingResults = is_array($bindingBatch['bindings'] ?? null) ? $bindingBatch['bindings'] : [];
                    }
                    $governedMediaOperations = [];
                    foreach ((array) ($context['media_operations'] ?? []) as $index => $mediaOperation) {
                        if (!is_array($mediaOperation)) throw new \RuntimeException('MEDIA_USAGE_OPERATION_INVALID');
                        $operation = strtolower(trim((string) ($mediaOperation['operation'] ?? '')));
                        $mediaRef = is_array($mediaOperation['media'] ?? null) ? $mediaOperation['media'] : (is_array($mediaOperation['media_ref'] ?? null) ? $mediaOperation['media_ref'] : []);
                        $media = $mediaBindingService->resolveMediaReference($mediaRef);
                        if ($operation === 'update') {
                            $expectedRevision = max(1, (int) ($mediaOperation['expected_revision'] ?? $media->revision));
                            $payload = array_replace($mediaOperation, ['operation' => $operation, 'media' => ['id' => $media->canonicalId], 'capture_id' => (string) ($context['capture']['capture_id'] ?? ''), 'capture_fingerprint' => (string) ($context['capture_fingerprint'] ?? '')]);
                            $environment = defined('WP_ENVIRONMENT_TYPE') ? strtolower((string) constant('WP_ENVIRONMENT_TYPE')) : (function_exists('wp_get_environment_type') ? strtolower((string) wp_get_environment_type()) : strtolower((string) (getenv('WP_ENVIRONMENT_TYPE') ?: 'unknown')));
                            if ($environment === 'staging') {
                                $captureRecord = $context['capture_record'] ?? null;
                                if (!$captureRecord instanceof \NHK\Core\Domain\Capture\CaptureRecord) throw new \RuntimeException('CAPTURE_SCOPE_BINDING_UNAVAILABLE');
                                $scope = $stagingScopeVerifier->issueForMediaMetadataUpdate(
                                    $captureRecord,
                                    $payload,
                                    $media->canonicalId,
                                    $expectedRevision,
                                );
                                $payload['staging_acceptance'] = $scope;
                            }
                            $governedMediaOperations[] = $mcpGovernance->ingestFromArguments([
                                'operation' => 'update', 'entity_type' => 'media', 'subject_id' => $media->canonicalId,
                                'expected_revision' => $expectedRevision,
                                'idempotency_key' => (string) ($mediaOperation['idempotency_key'] ?? ($context['capture']['capture_id'] ?? '') . ':media-metadata:' . $index),
                                'payload' => $payload,
                            ]);
                            continue;
                        }
                        $target = is_array($mediaOperation['target'] ?? null) ? $mediaOperation['target'] : [];
                        $targetUuid = null;
                        if (strtolower(trim((string) ($target['type'] ?? ''))) !== 'wp_post') {
                            $resolvedTarget = $mediaBindingService->resolveTargetReference($target);
                            $target = ['type' => $resolvedTarget->entityType, 'id' => $resolvedTarget->canonicalId];
                            $targetUuid = $resolvedTarget->canonicalId;
                        }
                        $payload = array_replace($mediaOperation, ['operation' => $operation, 'media' => ['id' => $media->canonicalId], 'target' => $target]);
                        $environment = defined('WP_ENVIRONMENT_TYPE') ? strtolower((string) constant('WP_ENVIRONMENT_TYPE')) : (function_exists('wp_get_environment_type') ? strtolower((string) wp_get_environment_type()) : strtolower((string) (getenv('WP_ENVIRONMENT_TYPE') ?: 'unknown')));
                        if (in_array($operation, ['add', 'replace', 'remove'], true) && $environment === 'staging' && $stagingScopeVerifier !== null) {
                            $captureRecord = $context['capture_record'] ?? null;
                            if (!$captureRecord instanceof \NHK\Core\Domain\Capture\CaptureRecord) throw new \RuntimeException('CAPTURE_SCOPE_BINDING_UNAVAILABLE');
                            $scope = $stagingScopeVerifier->issueForMediaUsageOperation($captureRecord, $payload);
                            $payload['capture_id'] = $captureRecord->captureId;
                            $payload['capture_fingerprint'] = $captureRecord->requestFingerprint;
                            $payload['staging_acceptance'] = $scope;
                        }
                        $governedMediaOperations[] = $mcpGovernance->ingestFromArguments([
                            'operation' => $operation, 'entity_type' => 'media', 'subject_id' => $media->canonicalId, 'target_uuid' => $targetUuid,
                            'expected_revision' => null, 'idempotency_key' => (string) ($mediaOperation['idempotency_key'] ?? ($context['capture']['capture_id'] ?? '') . ':media-operation:' . $index),
                            'target' => $target, 'payload' => $payload,
                        ]);
                    }
                    $metadataOnly = $typedBindings === [] && $governedMediaOperations !== [] && array_reduce(
                        (array) ($context['media_operations'] ?? []),
                        static fn (bool $only, mixed $operation): bool => $only && is_array($operation) && strtolower(trim((string) ($operation['operation'] ?? ''))) === 'update',
                        true,
                    );
                    if ($metadataOnly && strtoupper(trim((string) ($context['content_intent']['intent'] ?? ''))) === 'MEDIA_ENRICHMENT') {
                        $readback = [];
                        foreach ((array) ($context['media_operations'] ?? []) as $mediaOperation) {
                            $mediaRef = is_array($mediaOperation['media'] ?? null) ? $mediaOperation['media'] : (is_array($mediaOperation['media_ref'] ?? null) ? $mediaOperation['media_ref'] : []);
                            $current = $mediaBindingService->resolveMediaReference($mediaRef);
                            $readback[] = ['media_id' => $current->canonicalId, 'name' => $current->canonicalName, 'revision' => $current->revision, 'status' => 'verified'];
                        }
                        $mediaIds = array_values(array_unique(array_column($readback, 'media_id')));
                        $trace('MEDIA_METADATA_RECONCILIATION', 'VERIFIED', ['capture_id' => (string) ($context['capture']['capture_id'] ?? ''), 'media_count' => count($mediaIds)]);
                        return ['status' => 'RECONCILED', 'media_ids' => $mediaIds, 'media_complete' => true, 'blockers' => [], 'media_usage' => [], 'media_readback' => $readback, 'canonical_readback' => ['media' => $readback, 'media_usage' => []], 'frontend_verified' => null, 'binding_results' => [], 'governed_media_operations' => $governedMediaOperations, 'metadata_only' => true];
                    }
                    if (strtoupper(trim((string) ($context['content_intent']['intent'] ?? ''))) === 'MEDIA_ENRICHMENT') {
                        $trace('MEDIA_USAGE_RECONCILIATION', 'STARTED', ['capture_id' => (string) ($context['capture']['capture_id'] ?? '')]);
                        $primary = is_array($context['subject_resolution']['primary'] ?? null) ? $context['subject_resolution']['primary'] : [];
                        $explicitBindings = [];
                        foreach (['media_bindings', 'capture_media_bindings'] as $bindingKey) {
                            foreach ((array) ($context[$bindingKey] ?? []) as $binding) if (is_array($binding)) $explicitBindings[] = $binding;
                        }
                        $requests = (new \NHK\Core\Application\Media\MediaEnrichmentBindingRequestBuilder())->build(
                            $assets,
                            $primary,
                            $explicitBindings,
                            (string) ($context['capture']['capture_id'] ?? ''),
                        );
                        $bindingBatch = $requests === []
                            ? ['status' => 'PARTIAL', 'bindings' => [], 'media_ids' => []]
                            : $mediaBindingService->bindMany($requests, (string) ($context['capture']['capture_id'] ?? '') . ':media-enrichment', $assets);
                        $bindingResults = is_array($bindingBatch['bindings'] ?? null) ? $bindingBatch['bindings'] : [];
                        $mediaIds = [];
                        foreach ($bindingResults as $bindingResult) {
                            $candidateMediaId = trim((string) ($bindingResult['media_id'] ?? (($bindingResult['readback']['media_id'] ?? ''))));
                            if ($candidateMediaId !== '') $mediaIds[] = $candidateMediaId;
                        }
                        $mediaIds = array_values(array_unique($mediaIds));
                        $mediaReadback = [];
                        foreach ($mediaIds as $mediaId) {
                            $canonicalMedia = $media->findByCanonicalId($mediaId);
                            if ($canonicalMedia instanceof \NHK\Core\Domain\Media\Media) $mediaReadback[] = ['status' => 'verified', 'media_id' => $canonicalMedia->canonicalId, 'name' => $canonicalMedia->canonicalName, 'revision' => $canonicalMedia->revision];
                        }
                        $usageReadback = [];
                        foreach ($bindingResults as $bindingResult) $usageReadback[] = (array) ($bindingResult['readback'] ?? []);
                        $trace('MEDIA_USAGE_RECONCILIATION', $bindingBatch['status'] === 'COMPLETE' ? 'VERIFIED' : 'PARTIAL', ['capture_id' => (string) ($context['capture']['capture_id'] ?? ''), 'media_count' => count($mediaIds), 'usage_count' => count($usageReadback)]);
                        return [
                            'status' => $bindingBatch['status'] === 'COMPLETE' && $mediaIds !== [] ? 'RECONCILED' : 'PARTIAL',
                            'media_ids' => $mediaIds,
                            'media_complete' => $bindingBatch['status'] === 'COMPLETE',
                            'blockers' => $bindingBatch['status'] === 'COMPLETE' ? [] : ['MEDIA_BINDING_INCOMPLETE'],
                            'media_readback' => $mediaReadback,
                            'media_usage' => $usageReadback,
                            'canonical_readback' => ['media_ids' => $mediaIds, 'media_usage' => $usageReadback],
                            'frontend_verified' => null,
                            'binding_results' => $bindingResults,
                            'governed_media_operations' => $governedMediaOperations,
                        ];
                    }
                    $resolution = is_array($context['subject_resolution'] ?? null) ? $context['subject_resolution'] : [];
                    $packet = is_array($context['subject_resolution_packet'] ?? null) ? $context['subject_resolution_packet'] : [];
                    $packetId = trim((string) ($packet['canonical_subject_id'] ?? $packet['id'] ?? ''));
                    $packetType = trim((string) ($packet['entity_type'] ?? $packet['type'] ?? ''));
                    $packetName = trim((string) ($packet['canonical_name'] ?? $packet['name'] ?? ''));
                    $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : null;
                    if ($packetId !== '' && $packetType !== '') {
                        $primary = ['id' => $packetId, 'type' => $packetType, 'name' => $packetName, 'stable_key' => (string) ($packet['stable_key'] ?? ''), 'revision' => (int) ($packet['revision'] ?? 1)];
                        $resolution['status'] = 'resolved';
                        $resolution['primary'] = $primary;
                    }
                    $mediaSubjectIds = $primary !== null && trim((string) ($primary['id'] ?? '')) !== '' && ($resolution['status'] ?? '') === 'resolved' ? [trim((string) $primary['id'])] : [];
                    $subject = (string) ($primary['name'] ?? '');
                    $selected = [];
                    // Article-targeted bindings are separated from the
                    // generic Capture bindings during deferred resolution.
                    // Reconciliation must consume both projections; using
                    // only the first present array silently drops the current
                    // explicit publication-unit Media selection.
                    $captureBindings = [];
                    foreach ([
                        is_array($context['media_bindings'] ?? null) ? $context['media_bindings'] : [],
                        is_array($context['article_media_bindings'] ?? null) ? $context['article_media_bindings'] : [],
                        is_array($context['capture']['context']['media_bindings'] ?? null) ? $context['capture']['context']['media_bindings'] : [],
                        is_array($context['capture']['context']['article_media_bindings'] ?? null) ? $context['capture']['context']['article_media_bindings'] : [],
                    ] as $bindingSet) {
                        foreach ($bindingSet as $binding) if (is_array($binding)) $captureBindings[] = $binding;
                    }
                    $captureBindingMediaIds = [];
                    foreach ($captureBindings as $binding) {
                        if (!is_array($binding)) continue;
                        $target = is_array($binding['target'] ?? null) ? $binding['target'] : [];
                        if (strtolower(trim((string) ($target['type'] ?? ''))) !== 'wp_post') continue;
                        $mediaRef = is_array($binding['media_ref'] ?? null) ? $binding['media_ref'] : [];
                        $bindingMediaId = trim((string) ($mediaRef['media_id'] ?? $mediaRef['id'] ?? ''));
                        if ($bindingMediaId === '' && isset($mediaRef['item_index'])) $bindingMediaId = trim((string) (($assets[(int) $mediaRef['item_index']]['media_id'] ?? '')));
                        if ($bindingMediaId === '') continue;
                        $captureBindingMediaIds[] = $bindingMediaId;
                        $role = strtolower(trim((string) ($binding['role'] ?? '')));
                        if (!in_array($role, ['featured_primary', 'inline_primary'], true)) continue;
                        $seo = is_array($binding['seo'] ?? null) ? $binding['seo'] : [];
                        $selected[$role] = [
                            'media_id' => $bindingMediaId,
                            'title' => (string) ($seo['title'] ?? ''),
                            'alt_text' => (string) ($seo['alt_text'] ?? ''),
                            'caption' => (string) ($seo['caption'] ?? ''),
                            'sort_order' => (int) ($binding['sort_order'] ?? 0),
                            'selection_source' => strtoupper(trim((string) ($binding['selection_source'] ?? 'USER_EXPLICIT'))),
                            'selection_policy' => strtoupper(trim((string) ($binding['selection_policy'] ?? 'PINNED'))),
                        ];
                    }
                    $mediaIds = array_values(array_unique(array_merge($mediaIds, $captureBindingMediaIds)));
                    $captureMediaSelection = static function (array $asset): array {
                        $mediaContext = is_array($asset['media_context'] ?? null) ? $asset['media_context'] : [];
                        return ['media_id' => (string) ($asset['media_id'] ?? ''), 'title' => (string) ($mediaContext['title'] ?? ''), 'alt_text' => (string) ($mediaContext['alt_text'] ?? ''), 'caption' => (string) ($mediaContext['caption'] ?? ''), 'sort_order' => (int) ($asset['sort_order'] ?? 0)];
                    };
                    if (isset($assets[0]) && is_array($assets[0]) && !isset($selected['featured_primary'])) {
                        $selected['featured_primary'] = $captureMediaSelection($assets[0]);
                        // A single Capture image is the current publication
                        // plan for both mandatory editorial slots. Sharing one
                        // canonical Media identity is allowed and avoids
                        // replaying an older inline image from the Post.
                        if (!isset($mediaIds[1]) && !isset($selected['inline_primary'])) $selected['inline_primary'] = $captureMediaSelection($assets[0]);
                    }
                    if (isset($assets[1]) && is_array($assets[1]) && !isset($selected['inline_primary'])) $selected['inline_primary'] = $captureMediaSelection($assets[1]);
                    $supportingMedia = [];
                    foreach (array_slice($assets, 2) as $index => $asset) {
                        if (!is_array($asset) || trim((string) ($asset['media_id'] ?? '')) === '') continue;
                        $mediaContext = is_array($asset['media_context'] ?? null) ? $asset['media_context'] : [];
                        $supportingMedia[] = [
                            'media_id' => (string) $asset['media_id'],
                            'sort_order' => (int) ($asset['sort_order'] ?? ($index + 2)),
                            'placement_key' => 'capture:' . (string) ($asset['client_file_id'] ?? ('ordinal-' . ($index + 2))),
                            'title' => (string) ($mediaContext['title'] ?? ''),
                            'alt_text' => (string) ($mediaContext['alt_text'] ?? ''),
                            'caption' => (string) ($mediaContext['caption'] ?? ''),
                        ];
                    }
                    $result = $articleMedia->ensureForPost((int) ($context['article_id'] ?? 0), [
                        'capture_id' => (string) (($context['capture']['capture_id'] ?? '')),
                        'subject' => $subject,
                        'subject_ids' => $mediaSubjectIds,
                        'subject_context' => ['subject' => $subject, 'subject_ids' => $mediaSubjectIds],
                        'force_inline_reconcile' => true,
                        'capture_has_physical_assets' => $mediaIds !== [],
                        'capture_owned_media_ids' => $mediaIds,
                        'content_intent' => $context['content_intent'] ?? [],
                        'single_real_image_exception' => strtoupper(trim((string) ($context['content_intent']['intent'] ?? ''))) === 'IMAGE_ARTICLE' && count(array_values(array_unique($mediaIds))) === 1,
                        'subject_scope_locked' => $mediaSubjectIds !== [],
                        'allow_unscoped_reuse' => false,
                        'allow_scoped_reuse' => true,
                        'video_thumbnail_fallback' => $context['video_thumbnail_fallback'] ?? null,
                    ], $selected, $supportingMedia);
                    $payload = $result->toArray();
                    $payload['force_inline_reconcile'] = true;
                    $payload['editorial_state_token'] = $result->editorialStateToken;
                    $payload['binding_results'] = $bindingResults;
                    $payload['governed_media_operations'] = $governedMediaOperations;
                    return $payload;
                },
                static function (array $context) use ($draftGateway, $articleEditorial, $articleResearch, $articlePreflightHandoff, $categoryGateway): array {
                    // Media/editorial reconciliation can rotate the native
                    // token after Capture persisted its last receipt. On the
                    // bounded one-time refresh, re-read the Article owner and
                    // send that current token to the publication gate.
                    $expectedToken = (string) ($context['expected_state_token'] ?? '');
                    if (($context['refresh_current_state'] ?? false) === true) {
                        $current = $articleEditorial->read((int) ($context['article_id'] ?? 0));
                        if ($current !== null) $expectedToken = $current->token;
                    }
                    $resolution = is_array($context['subject_resolution'] ?? null) ? $context['subject_resolution'] : [];
                    $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
                    $current = $articleEditorial->read((int) ($context['article_id'] ?? 0));
                    if ($current !== null) $expectedToken = $current->token;
                    $composition = is_array($context['composition'] ?? null) ? $context['composition'] : [];
                    $topic = trim((string) ($composition['title'] ?? $current?->title ?? $context['capture']['context']['raw_input'] ?? ''));
                    $subjectPacket = is_array($context['subject_resolution_packet'] ?? null)
                        ? $context['subject_resolution_packet']
                        : (is_array($context['capture']['context']['subject_resolution_packet'] ?? null) ? $context['capture']['context']['subject_resolution_packet'] : []);
                    $freshResearch = $articleResearch->research($topic, $primary, [
                        'post_id' => (int) ($context['article_id'] ?? 0),
                        'title' => (string) ($current?->title ?? $topic),
                        'excerpt' => (string) ($current?->excerpt ?? ''),
                        'body' => (string) ($current?->content ?? ''),
                        'planned_title' => $topic,
                        'claim_trace' => is_array($composition['claim_trace'] ?? null) ? $composition['claim_trace'] : [],
                        'subject_resolution_packet' => $subjectPacket,
                    ]);
                    $desiredCategory = is_array($freshResearch->categoryPlan['desired_category'] ?? null) ? $freshResearch->categoryPlan['desired_category'] : [];
                    $currentCategory = is_array($freshResearch->categoryPlan['current_category'] ?? null) ? $freshResearch->categoryPlan['current_category'] : [];
                    $desiredCategoryId = (int) ($desiredCategory['id'] ?? 0);
                    $currentCategoryId = (int) ($currentCategory['id'] ?? 0);
                    if ($desiredCategoryId > 0 && $desiredCategoryId !== $currentCategoryId) {
                        $assigned = function_exists('wp_get_post_categories') ? array_map('intval', (array) wp_get_post_categories((int) ($context['article_id'] ?? 0), ['fields' => 'ids'])) : [];
                        foreach ($assigned as $termId) {
                            if ($termId === $desiredCategoryId) continue;
                            $term = function_exists('get_term') ? get_term($termId, 'category') : null;
                            if ($term instanceof \WP_Term && in_array(strtolower((string) $term->slug), ['uncategorized', 'chua-phan-loai'], true)) $categoryGateway->unassign((int) ($context['article_id'] ?? 0), $termId);
                        }
                        $categoryGateway->assign((int) ($context['article_id'] ?? 0), $desiredCategoryId);
                        $current = $articleEditorial->read((int) ($context['article_id'] ?? 0));
                        if ($current === null) return ['eligible' => false, 'blockers' => ['CATEGORY_PROJECTION_MISMATCH'], 'fresh_preflight' => $freshResearch->toArray()];
                        $expectedToken = $current->token;
                        $freshResearch = $articleResearch->research($topic, $primary, [
                            'post_id' => (int) ($context['article_id'] ?? 0),
                            'title' => $current->title,
                            'excerpt' => $current->excerpt,
                            'body' => $current->content,
                            'planned_title' => $topic,
                            'claim_trace' => is_array($composition['claim_trace'] ?? null) ? $composition['claim_trace'] : [],
                            'subject_resolution_packet' => $subjectPacket,
                        ]);
                    }
                    $evidence = $articlePreflightHandoff->build(
                        // The handoff supplies the gate's locked
                        // The resulting evidence contains 'subject_resolved' =>
                        // true/false from a fresh owner read.
                        $freshResearch,
                        is_array($context['media'] ?? null) ? $context['media'] : [],
                        is_array($context['semantic_write_back'] ?? null) ? $context['semantic_write_back'] : [],
                        array_replace($current?->snapshot() ?? [], ['content_intent' => strtoupper((string) (($context['capture']['content_intent']['intent'] ?? 'TEXT_ARTICLE')))]),
                    );
                    $evidence['semantic'] = $context['semantic'] ?? [];
                    $evidence['semantic_write_back'] = $context['semantic_write_back'] ?? [];
                    $evidence['media'] = $context['media'] ?? [];
                    $review = $draftGateway->reviewPublication((int) ($context['article_id'] ?? 0), $expectedToken, $evidence, (string) ($context['capture']['capture_id'] ?? '') . ':review');
                    $blockers = (array) ($review['blockers'] ?? $review['diagnostics'] ?? []);
                    return ['eligible' => (($review['outcome'] ?? '') === 'PASS' || ($review['eligible'] ?? false) === true) && $blockers === [], 'blockers' => $blockers, 'review' => $review, 'fresh_preflight' => $freshResearch->toArray(), 'state_token' => $review['state_token'] ?? $expectedToken];
                },
                static function (array $context) use ($articleEditorial, $mediaFinalReadback): array {
                    $articleId = (int) ($context['article_id'] ?? 0);
                    $media = is_array($context['media'] ?? null) ? $context['media'] : [];
                    $bindingResults = array_values(array_filter((array) ($media['bindings'] ?? []), 'is_array'));
                    if ($bindingResults !== []) {
                        $bindingVerified = count(array_filter($bindingResults, static fn (array $binding): bool => ($binding['status'] ?? '') === 'COMPLETE' && (($binding['readback']['status'] ?? '') === 'verified'))) === count($bindingResults);
                        if (!$bindingVerified) return ['status' => 'unavailable', 'reason' => 'MEDIA_BINDING_FINAL_READBACK_UNAVAILABLE', 'media_binding_count' => count($bindingResults)];
                    }
                    if ($articleId < 1) {
                        $intent = strtoupper(trim((string) (($context['content_intent']['intent'] ?? ''))));
                        if ($intent === 'MEDIA_ENRICHMENT') {
                            $metadataReadback = array_values(array_filter((array) ($media['media_readback'] ?? []), 'is_array'));
                            if (($media['metadata_only'] ?? false) === true) {
                                $expectedIds = array_values(array_unique(array_map(static fn (array $operation): string => trim((string) (($operation['media']['id'] ?? $operation['media_ref']['id'] ?? ''))), (array) ($context['media_operations'] ?? []))));
                                $actualIds = array_values(array_unique(array_map(static fn (array $item): string => trim((string) ($item['media_id'] ?? '')), $metadataReadback)));
                                sort($expectedIds); sort($actualIds);
                                if ($expectedIds === [] || $expectedIds !== $actualIds || count(array_filter($metadataReadback, static fn (array $item): bool => ($item['status'] ?? '') === 'verified' && trim((string) ($item['name'] ?? '')) !== '')) !== count($expectedIds)) return ['status' => 'unavailable', 'reason' => 'MEDIA_OWNER_READBACK_UNVERIFIED', 'media_readback' => $metadataReadback];
                                return ['status' => 'verified', 'frontend_verified' => null, 'media_readback' => $metadataReadback, 'media_usage' => [], 'article_owner' => 'NOT_REQUIRED'];
                            }
                            return $mediaFinalReadback->verify($media);
                        }
                        $payload = ['stage' => 'CAPTURE_FINAL_CANONICAL_READBACK', 'status' => 'VERIFIED', 'capture_id' => (string) ($context['capture']['capture_id'] ?? ''), 'article_owner' => 'NOT_REQUIRED', 'media_binding_count' => count($bindingResults), 'at' => gmdate('c')];
                        if (function_exists('do_action')) { try { do_action('nhk_v3_capture_stage_trace', $payload); } catch (\Throwable) { } }
                        if (function_exists('error_log')) {
                            $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            error_log('[nhk.capture.stage] ' . (is_string($encoded) ? $encoded : 'CAPTURE_FINAL_CANONICAL_READBACK'));
                        }
                        return ['status' => 'verified', 'post' => null, 'article_owner' => 'NOT_REQUIRED', 'media_binding_count' => count($bindingResults)];
                    }
                    $post = $articleEditorial->read($articleId);
                    return $post === null ? ['status' => 'unavailable'] : ['status' => 'verified', 'post' => $post->snapshot()];
                },
                static function (array $context) use ($draftGateway, $categoryGateway): array {
                    $postId = (int) ($context['article_id'] ?? 0);
                    $updated = $draftGateway->update($postId, (array) ($context['fields'] ?? []), (string) ($context['expected_state_token'] ?? ''), (string) ($context['capture_id'] ?? ''));
                    if (($updated['ok'] ?? false) !== true) return $updated;
                    $metadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];
                    $categoryName = trim((string) ($metadata['category_name'] ?? ''));
                    $categorySlug = trim((string) ($metadata['category_slug'] ?? ''));
                    if ($categoryName !== '' || $categorySlug !== '') {
                        $resolved = $categoryGateway->resolve(array_filter(['name' => $categoryName, 'slug' => $categorySlug], static fn (string $value): bool => $value !== ''));
                        if (($resolved['ok'] ?? false) !== true) return ['ok' => false, 'reason' => (string) ($resolved['reason'] ?? 'CATEGORY_NOT_FOUND'), 'post' => $updated['post'] ?? null, 'state_token' => $updated['state_token'] ?? ''];
                        $categoryId = (int) ($resolved['category']['id'] ?? 0);
                        if ($categoryId < 1) return ['ok' => false, 'reason' => 'CATEGORY_READBACK_UNAVAILABLE', 'post' => $updated['post'] ?? null, 'state_token' => $updated['state_token'] ?? ''];
                        $assigned = function_exists('wp_get_post_categories') ? array_map('intval', (array) wp_get_post_categories($postId, ['fields' => 'ids'])) : [];
                        foreach ($assigned as $termId) {
                            if ($termId === $categoryId) continue;
                            $term = function_exists('get_term') ? get_term($termId, 'category') : null;
                            if ($term instanceof \WP_Term && in_array(strtolower((string) $term->slug), ['uncategorized', 'chua-phan-loai'], true)) $categoryGateway->unassign($postId, $termId);
                        }
                        $categoryGateway->assign($postId, $categoryId);
                        // Category assignment is a native editorial mutation and
                        // advances the same state-token boundary as post fields.
                        $fresh = $articleEditorial->read($postId);
                        if ($fresh === null) return ['ok' => false, 'reason' => 'CATEGORY_READBACK_UNAVAILABLE', 'post' => $updated['post'] ?? null, 'state_token' => $updated['state_token'] ?? ''];
                        $updated['post'] = $fresh->snapshot();
                        $updated['state_token'] = $fresh->token;
                    }
                    return $updated;
                },
                static function (array $context) use ($attachmentBridge, $media): array {
                    $asset = is_array($context['asset'] ?? null) ? $context['asset'] : [];
                    $mediaContext = is_array($asset['media_context'] ?? null) ? $asset['media_context'] : [];
                    $mediaId = $attachmentBridge->adoptAttachment((int) ($context['attachment_id'] ?? 0), [
                        'canonical_name' => (string) ($mediaContext['title'] ?? ''),
                        'seo_slug' => (string) ($mediaContext['seo_slug'] ?? ''),
                        'description' => (string) ($mediaContext['description'] ?? ''),
                    ]);
                    $visualContexts = array_values(array_filter((array) ($context['visual_support_contexts'] ?? []), 'is_array'));
                    if ($mediaId !== null && $visualContexts !== []) {
                        $current = $media->findByCanonicalId($mediaId);
                        if ($current !== null) {
                            $provenance = $current->provenance;
                            $declared = is_array($provenance['visual_support_contexts'] ?? null) ? $provenance['visual_support_contexts'] : [];
                            foreach ($visualContexts as $visualContext) if (!in_array($visualContext, $declared, true)) $declared[] = $visualContext;
                            if ($declared !== (array) ($provenance['visual_support_contexts'] ?? [])) {
                                $provenance['visual_support_contexts'] = array_values($declared);
                                $media->update(new \NHK\Core\Domain\Media\Media($current->canonicalId, $current->stableKey, $current->canonicalName, $current->readiness, $provenance, $current->active, $current->revision), $current->revision);
                            }
                        }
                    }
                    return $mediaId === null ? ['status' => 'unavailable'] : ['status' => 'verified', 'media_id' => $mediaId, 'canonical_readback' => ['media_id' => $mediaId]];
                },
                static function (array $context) use ($draftGateway): array {
                    return $draftGateway->publish((int) ($context['article_id'] ?? 0), (string) ($context['expected_state_token'] ?? ''), (array) ($context['evidence'] ?? []), (string) ($context['idempotency_key'] ?? ''));
                },
                new McpDocumentationRegistry(),
                static function (array $context) use ($videoIntake): array {
                    $video = is_array($context['video'] ?? null) ? $context['video'] : [];
                    if (trim((string) ($video['url'] ?? '')) === '') return ['status' => 'unavailable', 'items' => [], 'diagnostics' => ['VIDEO_URL_REQUIRED']];
                    $resolution = is_array($context['subject_resolution'] ?? null) ? $context['subject_resolution'] : [];
                    $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : null;
                    $relations = is_array($video['intended_relations'] ?? null) ? $video['intended_relations'] : [];
                    if ($primary !== null && trim((string) ($primary['id'] ?? '')) !== '' && trim((string) ($primary['type'] ?? '')) !== '') {
                        $relations = array_values(array_filter($relations, static function (mixed $relation) use ($primary): bool {
                            if (!is_array($relation) || (string) ($relation['predicate'] ?? 'about') !== 'about') return true;
                            return (string) ($relation['target_id'] ?? '') === (string) $primary['id'] && (string) ($relation['target_type'] ?? '') === (string) $primary['type'];
                        }));
                    }
                    $preview = $videoIntake->preview(
                        (string) $video['url'],
                        (string) ($video['user_hint'] ?? ''),
                        isset($video['intended_category']) ? (string) $video['intended_category'] : null,
                        $relations,
                        (string) ($video['editorial_instruction'] ?? ''),
                        $primary,
                        trim((string) ($video['editorial_title'] ?? $context['editorial_title'] ?? '')),
                        trim((string) ($video['compliance_note'] ?? $context['compliance_note'] ?? '')),
                        true,
                        (string) ($context['attempt_id'] ?? ''),
                        (int) ($context['attempt_no'] ?? 0),
                    );
                    return [
                        'status' => 'verified',
                        'items' => [[
                            'kind' => 'video',
                            'video_id' => $preview->videoId,
                            'video_preview' => $preview->toArray(),
                            'video_proposal' => $videoIntake->proposalArguments($preview, (string) ($context['capture_id'] ?? '') . ':video'),
                        ]],
                        'video_preview' => $preview->toArray(),
                        'diagnostics' => ['editorial_quality' => $preview->internalDiagnostics],
                    ];
                },
                static function (array $context) use ($videoPublicationVerifier): array {
                    return $videoPublicationVerifier->verify($context);
                },
                new \NHK\Core\Application\Completion\CompletionCoordinator(static fn (string $ownerType): ?array => ($mediaCapabilities->forEndpoint($ownerType))?->toArray()),
                $clockTypeShadowClassifier,
                new \NHK\Core\Application\Capture\ContentIntentRouter(),
                new VisualOpportunityDetector(),
                new VisualSupportRequirementService(new \NHK\Core\Infrastructure\Media\WpdbVisualSupportRequirementRepository($wpdb)),
                $mediaBindingService,
                $stagingScopeVerifier,
                $canonicalDependencies,
                $articleEditorialAdapter,
                $contentPreparation,
                $sharedEnrichment,
                $captureFeatureBindings,
            );
            $captureContinuation = new EditorialCaptureContinuationService($captureRepository, $captureAddendumRepository, $capture, static function (array $input) use ($imageIngest, $existingMediaResolver): array {
                $mediaIds = is_array($input['media_ids'] ?? null) ? array_values($input['media_ids']) : [];
                if ($mediaIds !== []) return ['status' => 'verified', 'items' => $existingMediaResolver->resolve($mediaIds), 'reused' => true];
                return $imageIngest->ingest((string) ($input['idempotency_key'] ?? '') . ':assets', is_array($input['metadata'] ?? null) ? $input['metadata'] : [], $input['files'] ?? [], is_array($input['items'] ?? null) ? $input['items'] : [], (bool) ($input['_nhk_native_multipart'] ?? false));
            });
            $origin = static function (string $value): string { $parts = wp_parse_url($value); if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return ''; return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : ''); };
            $allowedOrigins = array_values(array_filter(array_unique([$origin((string) site_url()), $origin((string) home_url())])));
            $videoFrontendReconciliation = new \NHK\Core\Application\Video\VideoFrontendReconciliationService(
                $videos,
                $publicIdentityRepository,
                $videoFrontendReader->videoDetail(...),
                $videoFrontendReader->videoBySlug(...),
                $videoFrontendReader->videoArchive(...),
                static function () use ($homeSemanticQuery): array {
                    return $homeSemanticQuery->extend(['entities' => [], 'media' => [], 'videos' => [], 'knowledge' => [], 'hubs' => [], 'clock_groups' => [], 'explore_next' => [], 'latest_feed' => []]);
                },
            );
            $recoveryBinding = defined('NHK_RUNTIME_MODE') && strtolower((string) NHK_RUNTIME_MODE) === 'recovery'
                ? new \NHK\Core\Application\Mcp\RecoveryMcpRuntimeBinding($wpdb)
                : null;
            $knowledgeWriterPreview = new KnowledgeWriterPreviewService(
                $researchResolver,
                $captureSubjectResolver,
                $sharedEnrichment,
                new ReaderJourneyPlanner(),
                new SharedEditorialComposer(),
                new EditorialQualityGate(),
            );
            (new McpApi(new McpTransport($mcpRead, $mcpGovernance, static fn (string $capability): bool => current_user_can($capability), static fn (string $value): bool => in_array($value, $allowedOrigins, true), $articleHandler, $videoIntake, $wordpressAttachments, $categoryGateway, $draftGateway, new CanonicalDependencyValidator($claims, $sources, $evidence), $publicUrlMaintenance, $mediaBatchUpload, $documentation, $capture, $captureContinuation, $authorityCapture, static function (): bool { return (new MigrationStatus())->runtimeSchemaReady(); }, $imageIngest, semanticWritePolicy: $semanticWritePolicy, mediaBinding: $mediaBindingService, videoSourceRefresh: $videoSourceRefresh, knowledgeRepairPreview: $knowledgeRepairPreview, videoFrontendReconciliation: $videoFrontendReconciliation, knowledgeWriterPreview: $knowledgeWriterPreview), $recoveryBinding))->register();
            do_action('nhk_mcp_register_tools', McpToolCatalog::tools(), $mcpRead, $mcpGovernance);
        });
        add_action('admin_menu', [AdminPage::class, 'register']);
        add_action('admin_enqueue_scripts', static function (string $hookSuffix) use ($pluginFile): void {
            $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
            if (!AdminShell::isNhkScreen($hookSuffix, $page)) return;

            $version = defined('NHK_CORE_VERSION') ? (string) NHK_CORE_VERSION : '0.1.0';
            wp_register_style('nhk-v3-admin-shell', plugins_url('assets/admin.css', $pluginFile), [], $version);
            wp_enqueue_style('nhk-v3-admin-shell');
            wp_register_script('nhk-v3-admin-shell', plugins_url('assets/admin.js', $pluginFile), [], $version, true);
            wp_localize_script('nhk-v3-admin-shell', 'NHKAdminShell', [
                'restUrl' => esc_url_raw(rest_url('nhk/v1/')),
                'nonce' => wp_create_nonce('wp_rest'),
            ]);
            wp_enqueue_script('nhk-v3-admin-shell');
            if (str_starts_with($page, 'nhk-v3')) {
                wp_register_script('nhk-v3-admin-workbench', plugins_url('assets/admin/admin-workbench.js', $pluginFile), [], $version, true);
                wp_localize_script('nhk-v3-admin-workbench', 'nhkV3Admin', ['root' => esc_url_raw(rest_url('nhk/v1/')), 'nonce' => wp_create_nonce('wp_rest')]);
                wp_enqueue_script('nhk-v3-admin-workbench');
            }
        });
    }
    private static function canonicalInventory(EntityTypeRegistry $types, object $authority, object $media, object $videos, object $claims, object $sources, object $evidence): CanonicalInventoryService
    {
        $providers = [];
        foreach ($types->all() as $definition) {
            $type = $definition->type;
            $providers[$type] = static fn (): array => array_map(static fn (object $item): array => ['uuid' => $item->canonicalId, 'stable_key' => $item->stableKey, 'revision' => $item->revision, 'state' => $item->active() ? 'ACTIVE' : 'RETIRED', 'provenance' => $item->payload], $authority->listByType($type, true));
        }
        $providers['knowledge'] = static fn (): array => array_map(static fn (object $item): array => ['uuid' => $item->canonicalId, 'stable_key' => $item->stableKey, 'revision' => $item->revision, 'active' => $item->active, 'provenance' => $item->provenance, 'visibility' => $item->isPublic() ? 'PUBLIC' : 'PRIVATE'], $claims->list(true));
        $providers['source'] = static fn (): array => array_map(static fn (object $item): array => ['uuid' => $item->canonicalId, 'stable_key' => $item->stableKey, 'revision' => $item->revision, 'active' => $item->active, 'provenance' => $item->metadata, 'visibility' => $item->isPublic() ? 'PUBLIC' : 'PRIVATE'], $sources->list(true));
        $providers['evidence'] = static function () use ($claims, $evidence): array {
            $rows = [];
            foreach ($claims->list(true) as $claim) foreach ($evidence->listByClaim($claim->canonicalId, true) as $item) $rows[$item->canonicalId] = ['uuid' => $item->canonicalId, 'revision' => $item->revision, 'active' => $item->active, 'provenance' => $item->metadata, 'visibility' => $item->isPublic() ? 'PUBLIC' : 'PRIVATE'];
            return array_values($rows);
        };
        $providers['media'] = static fn (): array => array_map(static fn (object $item): array => ['uuid' => $item->canonicalId, 'stable_key' => $item->stableKey, 'revision' => $item->revision, 'active' => $item->active, 'provenance' => $item->provenance, 'visibility' => $item->active ? 'PUBLIC' : 'PRIVATE'], $media->list(true));
        $providers['video'] = static fn (): array => array_map(static fn (object $item): array => ['uuid' => $item->canonicalId, 'revision' => $item->revision, 'active' => $item->active, 'provenance' => $item->metadata, 'visibility' => $item->active ? 'PUBLIC' : 'PRIVATE'], $videos->list(true));
        return new CanonicalInventoryService($providers);
    }

    private static function relationBackfill(CanonicalInventoryService $canonicalInventory, GraphInventoryService $graphInventory): RelationBackfillService
    {
        $canonicalByType = [];
        foreach ($canonicalInventory->inventory([], 10000)->items as $record) {
            if (($record['stable_key'] ?? '') !== '' && ($record['uuid'] ?? '') !== '') $canonicalByType[(string) $record['type']][(string) $record['stable_key']] = (string) $record['uuid'];
        }
        $planner = new LegacyRelationPlanner($canonicalByType);
        $edges = $graphInventory->inventory([], 10000)->items;
        $exists = static function (RelationBackfillCandidate $candidate) use ($edges): bool {
            foreach ($edges as $edge) {
                if (($edge['source']['type'] ?? '') === $candidate->sourceType
                    && ($edge['source']['uuid'] ?? '') === $candidate->sourceUuid
                    && ($edge['predicate'] ?? '') === $candidate->predicate
                    && ($edge['target']['type'] ?? '') === $candidate->targetType
                    && ($edge['target']['uuid'] ?? '') === $candidate->targetUuid
                    && ($edge['state'] ?? '') === 'ACTIVE') return true;
            }
            return false;
        };
        return new RelationBackfillService(
            static fn (array $record): mixed => isset($record['edge_uuid']) ? ['status' => 'EXISTING'] : $planner->resolve($record),
            $exists,
            static fn (): array => array_merge($canonicalInventory->inventory([], 10000)->items, $graphInventory->inventory([], 10000)->items)
        );
    }

    private static function runtimeMigrationsEnabled(): bool
    {
        return defined('NHK_RUN_MIGRATIONS') && NHK_RUN_MIGRATIONS === true;
    }
    public static function runPendingMigrations(): void
    {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'PENDING_MIGRATIONS');
        if ((int) get_option('nhk_core_migration_current', 0) < ArticleIngestMigration010::VERSION) (new ArticleIngestMigration010())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < ArticleMediaMigration011::VERSION) (new ArticleMediaMigration011())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < MediaWordPressBridgeMigration012::VERSION) (new MediaWordPressBridgeMigration012())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < OwnerPublicationDecisionMigration013::VERSION) (new OwnerPublicationDecisionMigration013())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < PublicIdentityMigration014::VERSION || !PublicIdentityMigration014::schemaReady($wpdb)) (new PublicIdentityMigration014())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < DictionaryMigration015::VERSION || !DictionaryMigration015::schemaReady($wpdb)) (new DictionaryMigration015())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < ClaimProjectionMigration016::VERSION || !ClaimProjectionMigration016::schemaReady($wpdb)) (new ClaimProjectionMigration016())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < EditorialCaptureMigration017::VERSION || !EditorialCaptureMigration017::schemaReady($wpdb)) (new EditorialCaptureMigration017())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < EditorialCaptureAddendumMigration018::VERSION || !EditorialCaptureAddendumMigration018::schemaReady($wpdb)) (new EditorialCaptureAddendumMigration018())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < VisualSupportRequirementMigration019::VERSION || !VisualSupportRequirementMigration019::schemaReady($wpdb)) (new VisualSupportRequirementMigration019())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < GovernanceSubjectBindingMigration020::VERSION || !GovernanceSubjectBindingMigration020::schemaReady($wpdb)) (new GovernanceSubjectBindingMigration020())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < MediaUsageMetadataMigration021::VERSION || !MediaUsageMetadataMigration021::schemaReady($wpdb)) (new MediaUsageMetadataMigration021())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < MediaBindingOperationMigration022::VERSION || !MediaBindingOperationMigration022::schemaReady($wpdb)) (new MediaBindingOperationMigration022())->up();
    }
    public static function activate(): void {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'PLUGIN_ACTIVATION_MIGRATIONS');
        add_option('nhk_core_migration_current', 0, '', false);
        add_option('nhk_core_migration_target', MediaBindingOperationMigration022::VERSION, '', false);
        (new GraphMigration001())->up();
        (new AuthorityMigration002())->up();
        (new GovernanceMigration003())->up();
        (new MediaMigration004())->up();
        (new KnowledgeMigration005())->up();
        (new MigrationLedger006())->up();
        (new KnowledgeEvidenceMetadataMigration007())->up();
        (new MediaAssetMetadataMigration008())->up();
        (new ProjectionContextMigration009())->up();
        self::runPendingMigrations();
        GovernanceCapabilities::register();
        flush_rewrite_rules(false);
    }
    public static function deactivate(): void {}
}
