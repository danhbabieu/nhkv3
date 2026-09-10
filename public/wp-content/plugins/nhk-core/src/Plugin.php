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
use NHK\Core\Infrastructure\Migration\{EditorialCaptureAddendumMigration018, EditorialCaptureMigration017};
use NHK\Core\Infrastructure\Migration\MigrationDatabaseGuard;
use NHK\Core\Application\Governance\{AuthorityProposalExecutor, GovernanceCapabilities, GovernanceService, ProposalEligibilityService, WordPressGovernanceAuthorizer};
use NHK\Core\Application\Governance\ControlledApplyService;
use NHK\Core\Application\Authority\SemanticMergeService;
use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpArticleIngestHandler, McpGovernanceHandler, McpReadHandler, McpSemanticContextResolver, McpToolCatalog, McpTransport, McpDocumentationRegistry};
use NHK\Core\Application\Media\MediaBatchUploadService;
use NHK\Core\Application\Capture\{EditorialCaptureContinuationService, EditorialCaptureCoordinator, GovernedCaptureContinuationService};
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, ClaimReusePolicy, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Application\Article\{ArticleIngestCoordinator, ArticleIngestPreflight, ArticleResearchPreflight, ArticleVerificationReader, SemanticProposalPlanner, OwnerPublicationApplicationService};
use NHK\Core\Infrastructure\Http\ReadApi;
use NHK\Core\Infrastructure\Http\AdminWorkbenchReadApi;
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
use NHK\Core\Infrastructure\Mcp\EasyMcpNativeFileCompatibilityAdapter;
use NHK\Core\Infrastructure\Admin\AdminPage;
use NHK\Core\Infrastructure\Admin\AdminShell;
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository, WordPressImageSitemapProvider, WordPressMediaAttachmentBridge, WordPressMediaAttachmentIngestor, WordPressMediaAttachmentWriteGuard};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Infrastructure\PublicIdentity\WpdbPublicIdentityRepository;
use NHK\Core\Application\PublicIdentity\HistoricPublicRouteService;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Article\{WpEditorialStateReader, WpdbArticleOperationReceiptRepository, WpdbOwnerPublicationDecisionRepository};
use NHK\Core\Contracts\Article\PublicationPrincipal;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Application\Graph\{BrandAggregationQuery, GraphService, PredicateTraversalPolicy, RelatedSemanticQuery, SemanticNeighborhoodQuery, StructuralContextQuery};
use NHK\Core\Application\Graph\{LegacyRelationPlanner, RelationBackfillCandidate, RelationBackfillService};
use NHK\Core\Application\Inventory\{CanonicalInventoryService, GraphInventoryService};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, SemanticMergeGraphAdapter, WpdbAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Governance\{NoOpApplyExecutionHook, WpdbApplyAttemptRepository, WpdbDependencyRepository, WpdbEligibilityReader, WpdbProposalRepository};
use NHK\Core\Domain\Governance\DependencyGraph;
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Infrastructure\Authority\WpdbSemanticMergeReceiptRepository;
use NHK\Core\Application\Entity\{ComparisonPageQuery, EntityMediaProjection, EntityPageQuery, PublicEndpointEligibilityResolver, PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver, RelatedContentQuery};
use NHK\Core\Application\Media\{ArticleMediaCoordinator, ArticleMediaSeoProjection, MediaIngestGateway, MediaService, MediaVideoPageQuery};
use NHK\Core\Application\Video\{VideoCompletenessPolicy, VideoEditorialGenerator, VideoHubClassifier, VideoIntakeService, VideoInternalSemanticResearcher, VideoKnowledgeEnrichmentPlanner, VideoRelationCandidatePlanner, VideoSeoProjection, VideoService, YouTubeDataApiClient, YouTubeSourceAdapter};
use NHK\Core\Application\Home\HomeSemanticQuery;
use NHK\Core\Application\Search\SearchSemanticQuery;
use NHK\Core\Application\Knowledge\KnowledgePageQuery;
use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Collector\{CollectorFacetMaintenanceExecutor, CollectorFacetMaintenanceService};
use NHK\Core\Application\Governance\CanonicalApplyReadBackVerifier;
use NHK\Core\Application\WordPress\{CategoryGateway, EditorialDraftGateway};
use NHK\Core\Infrastructure\WordPress\{WpCategoryStore, WpEditorialPostStore};
use NHK\Core\Infrastructure\Capture\{WpdbCaptureAddendumRepository, WpdbCaptureRepository};

final class Plugin {
    private const REWRITE_VERSION = '10';
    public static function boot(string $pluginFile): void {
        // Keep an already-installed site aware of the code's migration target;
        // activation is not required for an upgrade health check to be honest.
        update_option('nhk_core_migration_target', EditorialCaptureAddendumMigration018::VERSION, false);
        if (self::runtimeMigrationsEnabled()) self::runPendingMigrations();
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
        add_action('wp_abilities_api_init', static function (): void {
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
            $graphEndpoints = new EndpointTypeRegistry();
            CoreEndpointResolverRegistrar::register($graphEndpoints, $types, $authority, $media, $videos, $claims, $sources, $evidence);
            $graphRead = new GraphService(new WpdbGraphRepository($wpdb), $graphEndpoints, new PredicateRegistry(), new WpdbAuditSink());
            $neighborhood = new SemanticNeighborhoodQuery(new RelatedSemanticQuery($graphRead, new PredicateTraversalPolicy(new PredicateRegistry())));
            $graphRepository = new WpdbGraphRepository($wpdb);
            $predicates = new PredicateRegistry();
            $canonicalInventory = self::canonicalInventory($types, $authority, $media, $videos, $claims, $sources, $evidence);
            $graphInventory = new GraphInventoryService($graphRepository, $graphEndpoints, $predicates);
            $relationBackfill = self::relationBackfill($canonicalInventory, $graphInventory);
            McpAbilityRegistration::registerReadAbilities(new McpReadHandler($authority, $types, $media, $assets, $usages, $videos, $claims, $evidence, new MigrationStatus(), $sources, null, new McpSemanticContextResolver($authority, $types), null, $neighborhood, $canonicalInventory, $graphInventory, $relationBackfill));
            McpAbilityRegistration::registerCapabilityGatedReadAbilities();
            McpAbilityRegistration::registerGovernedAbilities();
        });
        (new PublicEditorialRoutes())->register();
        LegacyUrlRedirects::register();
        global $wpdb;
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
            $publicContexts = new StructuralContextQuery($publicGraph, $publicAuthority);
            $publicRoutes = new PublicRouteResolver($publicAuthority, $publicTypes, $publicContexts);
            $publicEligibility = new PublicEntityEligibilityPolicy($publicAuthority, $publicTypes, $publicRoutes, $publicContexts);
            $publicAggregation = new BrandAggregationQuery($publicGraph, $publicAuthority, $publicTypes, $publicRoutes, $publicEligibility);
            $publicAssets = new WpdbMediaAssetRepository($wpdb);
            $publicUsages = new WpdbMediaUsageRepository($wpdb);
            $publicCollection = new PublicEntityCollectionQuery($publicAuthority, $publicTypes, new PublicIdentityContract($publicTypes), $publicEligibility, $publicRoutes, $publicAggregation, static fn (): bool => $publicStatus->authorityStorageReady(), new EntityMediaProjection($publicMedia, $publicAssets, $publicUsages));
            add_filter('nhk_v3_home_semantic_modules', [new HomeSemanticQuery($publicAuthority, $publicMedia, $publicVideos, $publicTypes, $publicStatus, $publicRoutes, $publicCollection), 'extend']);
            $publicClaims = new WpdbKnowledgeRepository($wpdb);
            $publicSources = new WpdbSourceRepository($wpdb);
            $publicEvidence = new WpdbEvidenceRepository($wpdb);
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
            $historicPublicRouteService = new HistoricPublicRouteService($publicIdentityRepository);
            (new PublicEntityRoutes($publicEntityQuery, $publicTypes, $historicPublicRouteService))->register();
            (new PublicComparisonRoutes(new ComparisonPageQuery($publicEntityQuery)))->register();
            $publicMediaService = new MediaService($publicMedia, $publicAssets, $publicUsages);
            $sharedAttachmentBridge = new WordPressMediaAttachmentBridge($wpdb, $publicMediaService, $publicMedia, $publicAssets);
            $attachmentBridge = $sharedAttachmentBridge;
            $articleMedia = new ArticleMediaCoordinator($publicMediaService, $publicMedia, $publicAssets, $publicUsages, new \NHK\Core\Infrastructure\Media\WpdbArticleMediaBlueprintRepository($wpdb), null, $attachmentBridge);
            $articleSeo = new ArticleMediaSeoProjection($publicMedia, $publicAssets, $publicUsages, $attachmentBridge);
            add_filter('nhk_v3_article_media_seo', static function (array $value, int $postId) use ($articleSeo): array { return $articleSeo->forPost((string) get_current_blog_id() . ':' . $postId); }, 10, 2);
            add_action('wp_sitemaps_init', static function (object $sitemaps) use ($articleSeo): void {
                if (isset($sitemaps->registry) && is_object($sitemaps->registry) && method_exists($sitemaps->registry, 'add_provider')) $sitemaps->registry->add_provider('images', new WordPressImageSitemapProvider($articleSeo));
            });
            $reconcilePostMedia = static function (int $postId, \WP_Post $post, bool $update) use ($articleMedia, $attachmentBridge): void {
                if ($attachmentBridge->isHandlingWrite()) return;
                if ($post->post_type !== 'post' || wp_is_post_revision($postId) || wp_is_post_autosave($postId)) return;
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
        add_action('rest_api_init', static function () use (&$sharedAttachmentBridge, $claimOwnerUrl): void {
            (new HealthCheck(new MigrationStatus()))->register_routes();
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) return;
            $media = new WpdbMediaRepository($wpdb); $assets = new WpdbMediaAssetRepository($wpdb); $usages = new WpdbMediaUsageRepository($wpdb); $videos = new WpdbVideoRepository($wpdb); $claims = new WpdbKnowledgeRepository($wpdb); $sources = new WpdbSourceRepository($wpdb); $evidence = new WpdbEvidenceRepository($wpdb); $authority = new WpdbAuthorityRepository($wpdb);
            (new ReadApi($media, $assets, $usages, $videos, $claims, $sources, $evidence, new MigrationStatus()))->register();
            $types = new EntityTypeRegistry();
            CanonicalEntityTypeCatalog::registerInto($types);
            $endpoints = new EndpointTypeRegistry(); CoreEndpointResolverRegistrar::register($endpoints, $types, $authority, $media, $videos, $claims, $sources, $evidence); $graphRepository = new WpdbGraphRepository($wpdb); $predicates = new PredicateRegistry(); $graphService = new GraphService($graphRepository, $endpoints, $predicates, new WpdbAuditSink());
            $publicStatus = new MigrationStatus();
            $publicContexts = new StructuralContextQuery($graphService, $authority);
            $publicRoutes = new PublicRouteResolver($authority, $types, $publicContexts);
            $publicEligibility = new PublicEntityEligibilityPolicy($authority, $types, $publicRoutes, $publicContexts);
            $publicCollection = new PublicEntityCollectionQuery($authority, $types, new PublicIdentityContract($types), $publicEligibility, $publicRoutes, new BrandAggregationQuery($graphService, $authority, $types, $publicRoutes, $publicEligibility), static fn (): bool => $publicStatus->authorityStorageReady(), new EntityMediaProjection($media, $assets, $usages));
            $proposalRepository = new WpdbProposalRepository($wpdb); $governanceAudit = new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb); $transactionManager = new WpdbTransactionManager($wpdb); $governance = new GovernanceService($proposalRepository, $governanceAudit, $transactionManager, new WordPressGovernanceAuthorizer());
            $eligibility = new ProposalEligibilityService($proposalRepository, new DependencyGraph(new WpdbDependencyRepository($wpdb)), new WpdbEligibilityReader($authority, $proposalRepository, $graphRepository, $media, $videos, $claims, $sources, $evidence));
            (new AdminWorkbenchReadApi($media, $videos, $claims, $authority, $sources, $evidence, $graphService, $proposalRepository, $eligibility, $assets, $usages))->register();
            $authorityService = new \NHK\Core\Application\Authority\AuthorityService($authority, $types, new \NHK\Core\Infrastructure\Authority\WpdbAuditSink(new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb)));
            $mediaService = new MediaService($media, $assets, $usages);
            $attachmentBridge = $sharedAttachmentBridge ?? new WordPressMediaAttachmentBridge($wpdb, $mediaService, $media, $assets);
            $sharedAttachmentBridge = $attachmentBridge;
            $merge = new SemanticMergeService($authority, [new SemanticMergeGraphAdapter($graphService)], static function (string $event, object $receipt) use ($governanceAudit): void {
                $governanceAudit->recordEvent($event, 'semantic_merge', (string) ($receipt->idempotencyKey ?? ''), null, $receipt->toArray());
            }, new WpdbSemanticMergeReceiptRepository($wpdb));
            $dependencyValidator = new CanonicalDependencyValidator($claims, $sources, $evidence);
            $canonicalReadBack = new CanonicalApplyReadBackVerifier(static function (string $entityType, string $id) use ($authority, $media, $videos, $claims, $sources, $evidence, $graphRepository): ?array {
                $record = match ($entityType) {
                    'knowledge' => $claims->findByCanonicalId($id),
                    'source' => $sources->findByCanonicalId($id),
                    'evidence' => $evidence->findByCanonicalId($id),
                    'video' => $videos->findByCanonicalId($id),
                    'media' => $media->findByCanonicalId($id),
                    'relation' => $graphRepository->findByUuid($id),
                    default => $authority->findByCanonicalId($id),
                };
                if ($record === null) return null;
                $actualType = property_exists($record, 'entityType') ? (string) $record->entityType : $entityType;
                $active = method_exists($record, 'active') ? (bool) $record->active() : (bool) ($record->active ?? (method_exists($record, 'isActive') ? $record->isActive() : false));
                $revision = (int) ($record->revision ?? 0);
                $canonicalId = (string) ($record->canonicalId ?? $record->edge_uuid ?? '');
                return ['entity_type' => $actualType, 'canonical_id' => $canonicalId, 'active' => $active, 'revision' => $revision, 'snapshot' => get_object_vars($record)];
            });
            $knowledgeService = new KnowledgeService($claims, $sources, $evidence);
            $historicalEvidence = new \NHK\Core\Application\Video\HistoricalVideoRelationEvidenceReconciliation($knowledgeService, $claims, $sources, $evidence, $proposalRepository);
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
            $collectorExecutor = new CollectorFacetMaintenanceExecutor($knowledgeService, $collectorBranchReader);
            $controlledApply = new ControlledApplyService($proposalRepository, new WpdbApplyAttemptRepository($wpdb), $transactionManager, new AuthorityProposalExecutor($authorityService, $graphService, $mediaService, new VideoService($videos), $knowledgeService, new MediaIngestGateway($mediaService, $attachmentBridge), $merge, dependencies: $dependencyValidator, completeness: new VideoCompletenessPolicy(), relationProposals: $proposalRepository, historicalEvidence: $historicalEvidence, collectorFacetExecutor: $collectorExecutor), $governanceAudit, $eligibility, new NoOpApplyExecutionHook(), new WordPressGovernanceAuthorizer(), $canonicalReadBack);
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
            $articleCoordinator = new ArticleIngestCoordinator(new WpdbArticleOperationReceiptRepository($wpdb), $articlePreflight, new SemanticProposalPlanner(), $articleEditorial, $governance, $controlledApply, $proposalRepository, new WpdbDependencyRepository($wpdb), new ArticleVerificationReader(), $articleMedia);
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
                static function (array $input) use ($authority, $types, $claims, $sources, $evidence, $media, $usages, $videos, $graphService, $predicates): array {
                    $primary = is_array($input['subject_resolution']['primary'] ?? null) ? $input['subject_resolution']['primary'] : [];
                    $subjects = is_array($input['subject_resolution']['subjects'] ?? null) ? $input['subject_resolution']['subjects'] : ($primary !== [] ? [$primary] : []);
                    $subjectIds = array_values(array_unique(array_filter(array_map(static fn (mixed $subject): string => is_array($subject) ? trim((string) ($subject['id'] ?? '')) : '', $subjects))));
                    $posts = function_exists('get_posts') ? array_map(static fn (\WP_Post $post): array => ['id' => (string) $post->ID, 'title' => (string) $post->post_title, 'published' => $post->post_status === 'publish', 'subject_ids' => []], get_posts(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => 50, 'no_found_rows' => true])) : [];
                    $articlePostId = (int) ($input['article_context']['post_id'] ?? 0);
                    $currentCategories = $articlePostId > 0 && function_exists('get_the_category')
                        ? array_map(static fn ($category): array => ['name' => (string) $category->name, 'slug' => (string) $category->slug], (array) get_the_category($articlePostId))
                        : [];
                    $articleMedia = [];
                    if ($articlePostId > 0) {
                        $articleEndpoint = (function_exists('get_current_blog_id') ? max(1, (int) get_current_blog_id()) : 1) . ':' . $articlePostId;
                        foreach (['featured_primary', 'inline_primary'] as $role) {
                            $usage = $usages->listByEndpoint('wp_post', $articleEndpoint, $role)[0] ?? null;
                            $mediaItem = $usage !== null ? $media->findByCanonicalId($usage->mediaId) : null;
                            $articleMedia[$role] = ['media_id' => $mediaItem?->canonicalId, 'placeholder' => $mediaItem?->isSystemPlaceholder() ?? true];
                        }
                        $articleMedia['media_complete'] = count($articleMedia) === 2
                            && !($articleMedia['featured_primary']['placeholder'] ?? true)
                            && !($articleMedia['inline_primary']['placeholder'] ?? true);
                        $articleMedia['diagnostics'] = $articleMedia['media_complete'] ? [] : [['code' => 'ARTICLE_MEDIA_INLINE_MISSING']];
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
                                    $page = $graphService->findIncoming($reference, 'about', $after, 200, false, 'knowledge');
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
                                    $next = $page['next_cursor'] ?? null;
                                    if ($next === null) break;
                                    if (!is_int($next) || $next <= $after) return ['status' => 'unavailable', 'reason' => 'GRAPH_RESEARCH_UNAVAILABLE'];
                                    $after = $next;
                                } while (true);
                            }
                        } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'GRAPH_RESEARCH_UNAVAILABLE']; }
                    }
                    $knowledgeRows = [];
                    $sourceRows = [];
                    $evidenceRows = [];
                    $knowledgeClaims = $branchKnowledge;
                    foreach ($claims->list() as $claim) {
                        $claimMetadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
                        $claimSubjectId = trim((string) ($claimMetadata['subject_id'] ?? ''));
                        if ($subjectIds !== [] && !in_array($claimSubjectId, $subjectIds, true)) continue;
                        if ($subjectIds !== [] && $claimSubjectId === '') continue;
                        $knowledgeClaims[$claim->canonicalId] = $claim;
                    }
                    foreach ($knowledgeClaims as $claim) {
                        $claimMetadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
                        $claimSubjectId = trim((string) ($claimMetadata['subject_id'] ?? ''));
                        $claimSubjectIds = array_values(array_unique(array_filter(array_map('strval', (array) ($branchKnowledgeSubjects[$claim->canonicalId] ?? [])))));
                        $claimEvidence = array_slice($evidence->listByClaim($claim->canonicalId), 0, 20);
                        $evidenceForClaim = [];
                        foreach ($claimEvidence as $item) {
                            $source = $sources->findByCanonicalId($item->sourceId);
                            $evidenceForClaim[] = ['id' => $item->canonicalId, 'relation' => $item->relation, 'excerpt' => $item->excerpt, 'locator' => $item->locator, 'active' => $item->active, 'public' => $item->isPublic(), 'source' => $source ? ['id' => $source->canonicalId, 'title' => $source->title, 'locator' => $source->locator, 'public' => $source->isPublic(), 'active' => $source->active] : null];
                            $evidenceRows[] = $evidenceForClaim[array_key_last($evidenceForClaim)];
                            if ($source !== null && count($sourceRows) < 50) $sourceRows[$source->canonicalId] = ['id' => $source->canonicalId, 'title' => $source->title, 'locator' => $source->locator, 'public' => $source->isPublic(), 'active' => $source->active];
                        }
                        $support = array_values(array_filter($evidenceForClaim, static fn (array $item): bool => $item['relation'] === 'supports' && $item['active'] === true));
                        $knowledgeRows[] = ['id' => $claim->canonicalId, 'subject_id' => $claimSubjectId !== '' ? $claimSubjectId : ($claimSubjectIds[0] ?? ''), 'subject_ids' => $claimSubjectIds, 'text' => $claim->claimText, 'scope' => $claim->claimType, 'active' => $claim->active, 'public' => $claim->isPublic(), 'evidence' => $evidenceForClaim, 'evidence_status' => $support === [] ? ($claimEvidence === [] ? 'NO_EVIDENCE' : 'INSUFFICIENT_EVIDENCE') : 'SUPPORTED_WITHIN_SCOPE'];
                    }
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
                    return ['status' => 'available', 'posts' => $posts, 'current_categories' => $currentCategories, 'article_media' => $articleMedia, 'categories' => function_exists('get_categories') ? array_map(static fn ($category): array => ['name' => $category->name, 'slug' => $category->slug], get_categories(['hide_empty' => false, 'number' => 50])) : [], 'authority' => $authorityRows, 'knowledge' => array_values($knowledgeRows), 'sources' => array_values($sourceRows), 'evidence' => $evidenceRows, 'media' => array_values($mediaRows), 'videos' => array_values($videoRows), 'relations' => $relations];
                },
                [$articlePublicEligibility, 'evaluate'],
            );
            $articleHandler = new McpArticleIngestHandler($articleCoordinator, $articlePreflight, $articleEditorial, $articleMedia, $articleResearch);
            (new GovernanceApi($governance, $eligibility, $controlledApply, $endpoints))->register();
            $videoRelationAdmin = new \NHK\Core\Application\Video\VideoRelationAdminService($governance, $proposalRepository, $videos, $authority, $knowledgeService, $claims, $sources, $evidence);
            (new VideoRelationAdminApi($videoRelationAdmin))->register();
            (new SearchApi($media, $videos, $claims, $authority, $types, $publicStatus, $publicCollection, $claimOwnerUrl))->register();
            (new EntityApi($authority, $types, $publicStatus, $publicCollection))->register();
            (new GraphApi($graphService, new MigrationStatus()))->register();
            $wordpressAttachments = new WordPressMediaAttachmentIngestor($attachmentBridge);
            $mediaBatchUpload = new MediaBatchUploadService($wordpressAttachments);
            $mcpNeighborhood = new SemanticNeighborhoodQuery(new RelatedSemanticQuery($graphService, new PredicateTraversalPolicy(new PredicateRegistry())));
            $canonicalInventory = self::canonicalInventory($types, $authority, $media, $videos, $claims, $sources, $evidence);
            $graphInventory = new GraphInventoryService($graphRepository, $endpoints, $predicates);
            $relationBackfill = self::relationBackfill($canonicalInventory, $graphInventory);
            $mcpRead = new McpReadHandler($authority, $types, $media, $assets, $usages, $videos, $claims, $evidence, new MigrationStatus(), $sources, null, new McpSemanticContextResolver($authority, $types), $wordpressAttachments, $mcpNeighborhood, $canonicalInventory, $graphInventory, $relationBackfill);
            $automationTypes = array_values(array_unique(array_merge(array_map(static fn ($definition): string => $definition->type, $types->all()), ['wp_post', 'media', 'video', 'knowledge', 'source', 'evidence'])));
            $automationResolver = new \NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver($automationTypes, new \NHK\Core\Infrastructure\Governance\WpOptionAutomationPolicyStorage($automationTypes));
            $mcpGovernance = new McpGovernanceHandler($governance, $eligibility, $controlledApply, $automationResolver, $endpoints);
            $captureClaimReuse = new ClaimReusePolicy();
            $captureGovernance = new GovernedCaptureContinuationService(
                $mcpGovernance,
                static fn (string $proposalId): array => $mcpGovernance->apply($proposalId),
                $automationResolver,
                static fn (string $capability): bool => current_user_can($capability),
                $captureClaimReuse,
            );
            $articleReceipts = new WpdbArticleOperationReceiptRepository($wpdb);
            $categoryGateway = new CategoryGateway(new WpCategoryStore());
            $editorialPosts = new WpEditorialPostStore($articleEditorial);
            $ownerPublication = new OwnerPublicationApplicationService($editorialPosts, new WpdbOwnerPublicationDecisionRepository($wpdb), static fn (PublicationPrincipal $principal): bool => current_user_can('nhk_ingest_articles') && current_user_can('publish_posts'), null, $articleReceipts);
            $draftGateway = new EditorialDraftGateway($editorialPosts, $articleReceipts, $ownerPublication);
            $captureRepository = new WpdbCaptureRepository($wpdb);
            $captureAddendumRepository = new WpdbCaptureAddendumRepository($wpdb);
            $captureSubjectResolver = new SubjectResolutionService(new \NHK\Core\Application\Semantic\CanonicalAuthoritySubjectResolver($authority, $types));
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
                        $rows[] = ['id' => $claim->canonicalId, 'revision' => $claim->revision, 'text' => $claim->claimText, 'subject_id' => $claimSubject, 'scope' => (string) ($metadata['scope'] ?? $claim->claimType), 'provenance' => (string) ($metadata['provenance'] ?? 'CATALOG_SUPPORTED'), 'evidence_status' => $support ? 'SUPPORTED_WITHIN_SCOPE' : 'INSUFFICIENT_EVIDENCE', 'relevance' => $claimSubject === (string) ($subject['id'] ?? '') ? 1.0 : 0.7];
                    }
                    return $rows;
                },
            );
            $youtubeConfiguration = new \NHK\Core\Application\Video\YouTubeApiConfiguration();
            $youtubeClient = static fn (object $identity): array => (new YouTubeDataApiClient(null, null, $youtubeConfiguration))->fetch($identity);
            $videoIntake = new VideoIntakeService(new YouTubeSourceAdapter($youtubeClient), $videos, new VideoHubClassifier(), new VideoRelationCandidatePlanner(new PredicateRegistry(), $evidence, $claims, $sources), new VideoEditorialGenerator(), new VideoCompletenessPolicy(), new VideoSeoProjection(), new VideoInternalSemanticResearcher($authority, $types), new VideoKnowledgeEnrichmentPlanner(new \NHK\Core\Application\Knowledge\KnowledgeEnrichmentPlanner($claims, $evidence, $sources)));
            $capture = new EditorialCaptureCoordinator(
                $captureRepository,
                static function (array $input) use ($mediaBatchUpload): array {
                    $files = is_array($input['files'] ?? null) ? $input['files'] : [];
                    $manifest = ['status' => 'verified', 'items' => [], 'count' => 0];
                    if ($files !== []) {
                        $manifest = $mediaBatchUpload->upload(
                            (string) ($input['idempotency_key'] ?? '') . ':assets',
                            is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
                            $files,
                            is_array($input['items'] ?? null) ? $input['items'] : [],
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
                    return ['status' => 'REVIEW_REQUIRED', 'writes' => array_merge($candidates, $videoCandidates), 'reused_claims' => $reusedClaims, 'relation_hints' => (array) ($interpretation['relation_hints'] ?? []), 'subject_resolution' => $context['subject_resolution'] ?? [], 'governance' => ['required_lifecycle' => ['PROPOSAL', 'SUBMIT', 'APPROVE', 'ELIGIBILITY', 'CONTROLLED_APPLY', 'CANONICAL_READ_BACK'], 'status' => 'REVIEW_REQUIRED'], 'blockers' => ['SEMANTIC_WRITE_BACK_REQUIRES_GOVERNANCE'], 'governance_available' => $mcpGovernance instanceof McpGovernanceHandler];
                },
                new ArticleComposer(),
                static function (array $context) use ($articleMedia): array {
                    $assets = is_array($context['assets'] ?? null) ? $context['assets'] : [];
                    $mediaIds = array_values(array_filter(array_map(static fn (mixed $asset): string => is_array($asset) ? trim((string) ($asset['media_id'] ?? '')) : '', $assets)));
                    $resolved = is_array($context['capture']['diagnostics']['subjects']['resolved'] ?? null) ? $context['capture']['diagnostics']['subjects']['resolved'] : [];
                    $variantSubjects = array_values(array_filter($resolved, static fn (mixed $subject): bool => is_array($subject) && (string) ($subject['type'] ?? '') === 'variant' && trim((string) ($subject['id'] ?? '')) !== ''));
                    $mediaSubjectIds = array_values(array_unique(array_map(static fn (array $subject): string => trim((string) $subject['id']), $variantSubjects)));
                    $subject = (string) (($variantSubjects[0]['name'] ?? '') ?: ($resolved[0]['name'] ?? ''));
                    $selected = [];
                    if (isset($mediaIds[0])) {
                        $selected['featured_primary'] = $mediaIds[0];
                        // A single Capture image is the current publication
                        // plan for both mandatory editorial slots. Sharing one
                        // canonical Media identity is allowed and avoids
                        // replaying an older inline image from the Post.
                        if (!isset($mediaIds[1])) $selected['inline_primary'] = $mediaIds[0];
                    }
                    if (isset($mediaIds[1])) $selected['inline_primary'] = $mediaIds[1];
                    $result = $articleMedia->ensureForPost((int) ($context['article_id'] ?? 0), [
                        'capture_id' => (string) (($context['capture']['capture_id'] ?? '')),
                        'subject' => $subject,
                        'subject_ids' => $mediaSubjectIds,
                        'subject_context' => ['subject' => $subject, 'subject_ids' => $mediaSubjectIds],
                        'force_inline_reconcile' => true,
                        'capture_has_physical_assets' => $mediaIds !== [],
                        'allow_unscoped_reuse' => false,
                        'allow_scoped_reuse' => true,
                    ], $selected, array_slice($mediaIds, 2));
                    $payload = $result->toArray();
                    $payload['force_inline_reconcile'] = true;
                    $payload['editorial_state_token'] = $result->editorialStateToken;
                    return $payload;
                },
                static function (array $context) use ($draftGateway): array {
                    $review = $draftGateway->reviewPublication((int) ($context['article_id'] ?? 0), (string) ($context['expected_state_token'] ?? ''), ['semantic' => $context['semantic'] ?? [], 'media' => $context['media'] ?? []], (string) ($context['capture']['capture_id'] ?? '') . ':review');
                    $blockers = (array) ($review['blockers'] ?? $review['diagnostics'] ?? []);
                    return ['eligible' => (($review['outcome'] ?? '') === 'PASS' || ($review['eligible'] ?? false) === true) && $blockers === [], 'blockers' => $blockers, 'review' => $review];
                },
                static function (array $context) use ($articleEditorial): array {
                    $post = $articleEditorial->read((int) ($context['article_id'] ?? 0));
                    return $post === null ? ['status' => 'unavailable'] : ['status' => 'verified', 'post' => $post->snapshot()];
                },
                static function (array $context) use ($draftGateway): array {
                    return $draftGateway->update((int) ($context['article_id'] ?? 0), (array) ($context['fields'] ?? []), (string) ($context['expected_state_token'] ?? ''));
                },
                static function (array $context) use ($attachmentBridge): array {
                    $mediaId = $attachmentBridge->adoptAttachment((int) ($context['attachment_id'] ?? 0));
                    return $mediaId === null ? ['status' => 'unavailable'] : ['status' => 'verified', 'media_id' => $mediaId];
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
                    ];
                },
            );
            $captureContinuation = new EditorialCaptureContinuationService($captureRepository, $captureAddendumRepository, $capture);
            $origin = static function (string $value): string { $parts = wp_parse_url($value); if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return ''; return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : ''); };
            $allowedOrigins = array_values(array_filter(array_unique([$origin((string) site_url()), $origin((string) home_url())])));
            $publicUrlMaintenance = (new \NHK\Core\Infrastructure\PublicIdentity\WordPressPublicUrlMaintenanceRuntime($wpdb, $authority, $types, $publicContexts, $videos, $media, $assets, new \NHK\Core\Infrastructure\PublicIdentity\WpdbPublicIdentityRepository($wpdb)))->service();
            $documentation = new McpDocumentationRegistry();
            (new McpApi(new McpTransport($mcpRead, $mcpGovernance, static fn (string $capability): bool => current_user_can($capability), static fn (string $value): bool => in_array($value, $allowedOrigins, true), $articleHandler, $videoIntake, $wordpressAttachments, $categoryGateway, $draftGateway, new CanonicalDependencyValidator($claims, $sources, $evidence), $publicUrlMaintenance, $mediaBatchUpload, $documentation, $capture, $captureContinuation)))->register();
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
    }
    public static function activate(): void {
        global $wpdb;
        MigrationDatabaseGuard::assertUpAllowed((string) $wpdb->get_var('SELECT DATABASE()'), 'PLUGIN_ACTIVATION_MIGRATIONS');
        add_option('nhk_core_migration_current', 0, '', false);
        add_option('nhk_core_migration_target', EditorialCaptureAddendumMigration018::VERSION, '', false);
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
