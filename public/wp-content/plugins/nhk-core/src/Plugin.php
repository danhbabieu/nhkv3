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
use NHK\Core\Application\Governance\{AuthorityProposalExecutor, GovernanceCapabilities, GovernanceService, ProposalEligibilityService, WordPressGovernanceAuthorizer};
use NHK\Core\Application\Governance\ControlledApplyService;
use NHK\Core\Application\Authority\SemanticMergeService;
use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpArticleIngestHandler, McpGovernanceHandler, McpReadHandler, McpSemanticContextResolver, McpToolCatalog, McpTransport};
use NHK\Core\Application\Article\{ArticleIngestCoordinator, ArticleIngestPreflight, ArticleResearchPreflight, ArticleVerificationReader, SemanticProposalPlanner, OwnerPublicationApplicationService};
use NHK\Core\Infrastructure\Http\ReadApi;
use NHK\Core\Infrastructure\Http\GovernanceApi;
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
use NHK\Core\Infrastructure\Admin\AdminPage;
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository, WordPressImageSitemapProvider, WordPressMediaAttachmentBridge, WordPressMediaAttachmentIngestor, WordPressMediaAttachmentWriteGuard};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Article\{WpEditorialStateReader, WpdbArticleOperationReceiptRepository, WpdbOwnerPublicationDecisionRepository};
use NHK\Core\Contracts\Article\PublicationPrincipal;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Application\Graph\{BrandAggregationQuery, GraphService, PredicateTraversalPolicy, RelatedSemanticQuery, StructuralContextQuery};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, SemanticMergeGraphAdapter, WpdbAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Governance\{NoOpApplyExecutionHook, WpdbApplyAttemptRepository, WpdbDependencyRepository, WpdbEligibilityReader, WpdbProposalRepository};
use NHK\Core\Domain\Governance\DependencyGraph;
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Infrastructure\Authority\WpdbSemanticMergeReceiptRepository;
use NHK\Core\Application\Entity\{ComparisonPageQuery, EntityPageQuery, PublicEndpointEligibilityResolver, PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver, RelatedContentQuery};
use NHK\Core\Application\Media\{ArticleMediaCoordinator, ArticleMediaSeoProjection, MediaIngestGateway, MediaService, MediaVideoPageQuery};
use NHK\Core\Application\Video\{VideoCompletenessPolicy, VideoEditorialGenerator, VideoHubClassifier, VideoIntakeService, VideoInternalSemanticResearcher, VideoRelationCandidatePlanner, VideoSeoProjection, VideoService, YouTubeDataApiClient, YouTubeSourceAdapter};
use NHK\Core\Application\Home\HomeSemanticQuery;
use NHK\Core\Application\Search\SearchSemanticQuery;
use NHK\Core\Application\Knowledge\KnowledgePageQuery;
use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Application\WordPress\{CategoryGateway, EditorialDraftGateway};
use NHK\Core\Infrastructure\WordPress\{WpCategoryStore, WpEditorialPostStore};

final class Plugin {
    private const REWRITE_VERSION = '9';
    public static function boot(string $pluginFile): void {
        // Keep an already-installed site aware of the code's migration target;
        // activation is not required for an upgrade health check to be honest.
        update_option('nhk_core_migration_target', OwnerPublicationDecisionMigration013::VERSION, false);
        if ((int) get_option('nhk_core_migration_current', 0) < ArticleIngestMigration010::VERSION) (new ArticleIngestMigration010())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < ArticleMediaMigration011::VERSION) (new ArticleMediaMigration011())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < MediaWordPressBridgeMigration012::VERSION) (new MediaWordPressBridgeMigration012())->up();
        if ((int) get_option('nhk_core_migration_current', 0) < OwnerPublicationDecisionMigration013::VERSION) (new OwnerPublicationDecisionMigration013())->up();
        if ((string) get_option('nhk_core_rewrite_version', '') !== self::REWRITE_VERSION) { update_option('nhk_core_rewrite_version', self::REWRITE_VERSION, false); add_action('init', static function (): void { flush_rewrite_rules(false); }, 99); }
        // Register capabilities on every load so existing installations and
        // upgrades do not need a deactivate/activate cycle to authorize P4.
        GovernanceCapabilities::register();
        add_action('wp_abilities_api_categories_init', [McpAbilityRegistration::class, 'registerCategory']);
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
            McpAbilityRegistration::registerReadAbilities(new McpReadHandler($authority, $types, $media, $assets, $usages, $videos, $claims, $evidence, new MigrationStatus(), $sources, null, new McpSemanticContextResolver($authority, $types)));
            McpAbilityRegistration::registerCapabilityGatedReadAbilities();
            McpAbilityRegistration::registerGovernedAbilities();
        });
        (new PublicEditorialRoutes())->register();
        LegacyUrlRedirects::register();
        global $wpdb;
        $sharedAttachmentBridge = null;
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
            $publicCollection = new PublicEntityCollectionQuery($publicAuthority, $publicTypes, new PublicIdentityContract($publicTypes), $publicEligibility, $publicRoutes, $publicAggregation, static fn (): bool => $publicStatus->authorityStorageReady());
            add_filter('nhk_v3_home_semantic_modules', [new HomeSemanticQuery($publicAuthority, $publicMedia, $publicVideos, $publicTypes, $publicStatus, $publicRoutes, $publicCollection), 'extend']);
            $publicClaims = new WpdbKnowledgeRepository($wpdb);
            $publicSources = new WpdbSourceRepository($wpdb);
            $publicEvidence = new WpdbEvidenceRepository($wpdb);
            add_filter('nhk_v3_search_semantic_results', [new SearchSemanticQuery($publicAuthority, $publicMedia, $publicVideos, $publicClaims, $publicTypes, $publicStatus, $publicRoutes, $publicCollection), 'extend'], 10, 3);
            $publicRelated = new RelatedContentQuery($publicGraph, $publicAuthority, $publicMedia, $publicVideos, $publicTypes, $publicStatus, $publicEligibility);
            add_filter('nhk_v3_post_related_content', static function (array $value, int $postId) use ($publicRelated): array { return $publicRelated->forPost($postId); }, 10, 2);
            $publicEntityQuery = new EntityPageQuery($publicAuthority, $publicTypes, $publicRelated, $publicStatus, $publicRoutes, $publicCollection);
            (new PublicEntityRoutes($publicEntityQuery, $publicTypes))->register();
            (new PublicComparisonRoutes(new ComparisonPageQuery($publicEntityQuery)))->register();
            $publicAssets = new WpdbMediaAssetRepository($wpdb);
            $publicUsages = new WpdbMediaUsageRepository($wpdb);
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
            (new PublicMediaVideoRoutes(new MediaVideoPageQuery($publicMedia, $publicAssets, $publicUsages, $publicVideos, $publicStatus, null, $publicRelated)))->register();
            (new PublicVideoSitemapRoutes($publicVideos, $publicStatus))->register();
            $mediaRoot = defined('NHK_MEDIA_STORAGE_ROOT') ? (string) NHK_MEDIA_STORAGE_ROOT : (string) (getenv('NHK_MEDIA_STORAGE_ROOT') ?: '');
            if ($mediaRoot === '' && function_exists('wp_upload_dir')) { $upload = wp_upload_dir(); $mediaRoot = is_array($upload) ? (string) ($upload['basedir'] ?? '') : ''; }
            (new PublicMediaAssetRoutes(new \NHK\Core\Application\Media\PublicMediaAssetDelivery($publicAssets, $publicMedia, $mediaRoot)))->register();
            (new PublicKnowledgeRoutes(new KnowledgePageQuery($publicClaims, $publicEvidence, $publicSources, $publicStatus)))->register();
        }
        add_action('rest_api_init', static function () use (&$sharedAttachmentBridge): void {
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
            $publicCollection = new PublicEntityCollectionQuery($authority, $types, new PublicIdentityContract($types), $publicEligibility, $publicRoutes, new BrandAggregationQuery($graphService, $authority, $types, $publicRoutes, $publicEligibility), static fn (): bool => $publicStatus->authorityStorageReady());
            $proposalRepository = new WpdbProposalRepository($wpdb); $governanceAudit = new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb); $transactionManager = new WpdbTransactionManager($wpdb); $governance = new GovernanceService($proposalRepository, $governanceAudit, $transactionManager, new WordPressGovernanceAuthorizer());
            $eligibility = new ProposalEligibilityService($proposalRepository, new DependencyGraph(new WpdbDependencyRepository($wpdb)), new WpdbEligibilityReader($authority, $proposalRepository, $graphRepository, $media, $videos, $claims, $sources, $evidence));
            $authorityService = new \NHK\Core\Application\Authority\AuthorityService($authority, $types, new \NHK\Core\Infrastructure\Authority\WpdbAuditSink(new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb)));
            $mediaService = new MediaService($media, $assets, $usages);
            $attachmentBridge = $sharedAttachmentBridge ?? new WordPressMediaAttachmentBridge($wpdb, $mediaService, $media, $assets);
            $sharedAttachmentBridge = $attachmentBridge;
            $merge = new SemanticMergeService($authority, [new SemanticMergeGraphAdapter($graphService)], static function (string $event, object $receipt) use ($governanceAudit): void {
                $governanceAudit->recordEvent($event, 'semantic_merge', (string) ($receipt->idempotencyKey ?? ''), null, $receipt->toArray());
            }, new WpdbSemanticMergeReceiptRepository($wpdb));
            $controlledApply = new ControlledApplyService($proposalRepository, new WpdbApplyAttemptRepository($wpdb), $transactionManager, new AuthorityProposalExecutor($authorityService, $graphService, $mediaService, new VideoService($videos), new KnowledgeService($claims, $sources, $evidence), new MediaIngestGateway($mediaService, $attachmentBridge), $merge), $governanceAudit, $eligibility, new NoOpApplyExecutionHook(), new WordPressGovernanceAuthorizer());
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
                static function (array $input) use ($authority, $types, $claims, $sources, $evidence, $media, $videos, $graphService, $predicates): array {
                    $primary = is_array($input['subject_resolution']['primary'] ?? null) ? $input['subject_resolution']['primary'] : [];
                    $posts = function_exists('get_posts') ? array_map(static fn (\WP_Post $post): array => ['id' => (string) $post->ID, 'title' => (string) $post->post_title, 'published' => $post->post_status === 'publish', 'subject_ids' => []], get_posts(['post_type' => 'post', 'post_status' => ['publish', 'draft', 'private'], 'posts_per_page' => 50, 'no_found_rows' => true])) : [];
                    $authorityRows = [];
                    foreach ($types->all() as $definition) foreach ($authority->listByType($definition->type) as $entity) $authorityRows[] = ['id' => $entity->canonicalId, 'type' => $entity->entityType, 'name' => $entity->canonicalName, 'active' => $entity->active()];
                    $knowledgeRows = [];
                    $sourceRows = [];
                    $evidenceRows = [];
                    foreach (array_slice($claims->list(), 0, 50) as $claim) {
                        $claimEvidence = array_slice($evidence->listByClaim($claim->canonicalId), 0, 20);
                        $evidenceForClaim = [];
                        foreach ($claimEvidence as $item) {
                            $source = $sources->findByCanonicalId($item->sourceId);
                            $evidenceForClaim[] = ['id' => $item->canonicalId, 'relation' => $item->relation, 'excerpt' => $item->excerpt, 'locator' => $item->locator, 'active' => $item->active, 'public' => $item->isPublic(), 'source' => $source ? ['id' => $source->canonicalId, 'title' => $source->title, 'locator' => $source->locator, 'public' => $source->isPublic(), 'active' => $source->active] : null];
                            $evidenceRows[] = $evidenceForClaim[array_key_last($evidenceForClaim)];
                            if ($source !== null && count($sourceRows) < 50) $sourceRows[$source->canonicalId] = ['id' => $source->canonicalId, 'title' => $source->title, 'locator' => $source->locator, 'public' => $source->isPublic(), 'active' => $source->active];
                        }
                        $support = array_values(array_filter($evidenceForClaim, static fn (array $item): bool => $item['relation'] === 'supports' && $item['active'] === true));
                        $knowledgeRows[] = ['id' => $claim->canonicalId, 'text' => $claim->claimText, 'scope' => $claim->claimType, 'active' => $claim->active, 'public' => $claim->isPublic(), 'evidence' => $evidenceForClaim, 'evidence_status' => $support === [] ? ($claimEvidence === [] ? 'NO_EVIDENCE' : 'INSUFFICIENT_EVIDENCE') : 'SUPPORTED_WITHIN_SCOPE'];
                    }
                    $mediaRows = array_map(static fn ($item): array => ['id' => $item->canonicalId, 'ready' => $item->readiness === 'ready', 'public' => $item->active], $media->list());
                    $videoRows = array_map(static fn ($item): array => ['id' => $item->canonicalId, 'public' => $item->active && $item->hasValidPublicReference()], $videos->list());
                    $relations = [];
                    $subjects = is_array($input['subject_resolution']['subjects'] ?? null) ? $input['subject_resolution']['subjects'] : ($primary !== [] ? [$primary] : []);
                    if ($subjects !== []) {
                        try {
                            $query = new RelatedSemanticQuery($graphService, new PredicateTraversalPolicy($predicates));
                            foreach ($subjects as $subject) {
                                $ref = new \NHK\Core\Domain\Graph\NodeReference((string) $subject['type'], (string) $subject['id']);
                                $related = $query->query($ref, [], 2, 50);
                                foreach ($related['items'] as $item) $relations[$item['target_entity_type'].':'.$item['target_entity_id']] = ['class' => $item['relationship_class'], 'predicate' => $item['best_path'][array_key_last($item['best_path'])]['predicate'] ?? '', 'target_id' => $item['target_entity_id'], 'target_type' => $item['target_entity_type'], 'path' => $item['best_path'], 'reason' => 'registered Graph traversal'];
                                foreach ($graphService->findIncoming($ref, null, 0, 50)['items'] as $edge) { $postRef = $edge->source->reference; if ($postRef->endpoint_type !== 'wp_post') continue; foreach ($posts as &$post) if ($post['id'] === substr($postRef->endpoint_key, strpos($postRef->endpoint_key, ':') + 1)) $post['subject_ids'][] = $subject['id']; unset($post); }
                            }
                            $relations = array_values($relations);
                        } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'GRAPH_RESEARCH_UNAVAILABLE']; }
                    }
                    return ['status' => 'available', 'posts' => $posts, 'categories' => function_exists('get_categories') ? array_map(static fn ($category): array => ['name' => $category->name, 'slug' => $category->slug], get_categories(['hide_empty' => false, 'number' => 50])) : [], 'authority' => $authorityRows, 'knowledge' => $knowledgeRows, 'sources' => array_values($sourceRows), 'evidence' => $evidenceRows, 'media' => $mediaRows, 'videos' => $videoRows, 'relations' => $relations];
                },
                [$articlePublicEligibility, 'evaluate'],
            );
            $articleHandler = new McpArticleIngestHandler($articleCoordinator, $articlePreflight, $articleEditorial, $articleMedia, $articleResearch);
            (new GovernanceApi($governance, $eligibility, $controlledApply))->register();
            (new SearchApi($media, $videos, $claims, $authority, $types, $publicStatus, $publicCollection))->register();
            (new EntityApi($authority, $types, $publicStatus, $publicCollection))->register();
            (new GraphApi($graphService, new MigrationStatus()))->register();
            $wordpressAttachments = new WordPressMediaAttachmentIngestor();
            $mcpRead = new McpReadHandler($authority, $types, $media, $assets, $usages, $videos, $claims, $evidence, new MigrationStatus(), $sources, null, new McpSemanticContextResolver($authority, $types), $wordpressAttachments);
            $mcpGovernance = new McpGovernanceHandler($governance, $eligibility, $controlledApply);
            $articleReceipts = new WpdbArticleOperationReceiptRepository($wpdb);
            $categoryGateway = new CategoryGateway(new WpCategoryStore());
            $editorialPosts = new WpEditorialPostStore($articleEditorial);
            $ownerPublication = new OwnerPublicationApplicationService($editorialPosts, new WpdbOwnerPublicationDecisionRepository($wpdb), static fn (PublicationPrincipal $principal): bool => current_user_can('nhk_ingest_articles') && current_user_can('publish_posts'));
            $draftGateway = new EditorialDraftGateway($editorialPosts, $articleReceipts, $ownerPublication);
            $youtubeConfiguration = new \NHK\Core\Application\Video\YouTubeApiConfiguration();
            $youtubeClient = static fn (object $identity): array => (new YouTubeDataApiClient(null, null, $youtubeConfiguration))->fetch($identity);
            $videoIntake = new VideoIntakeService(new YouTubeSourceAdapter($youtubeClient), $videos, new VideoHubClassifier(), new VideoRelationCandidatePlanner(new PredicateRegistry(), $evidence, $claims, $sources), new VideoEditorialGenerator(), new VideoCompletenessPolicy(), new VideoSeoProjection(), new VideoInternalSemanticResearcher($authority, $types));
            $origin = static function (string $value): string { $parts = wp_parse_url($value); if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return ''; return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : ''); };
            $allowedOrigins = array_values(array_filter(array_unique([$origin((string) site_url()), $origin((string) home_url())])));
            (new McpApi(new McpTransport($mcpRead, $mcpGovernance, static fn (string $capability): bool => current_user_can($capability), static fn (string $value): bool => in_array($value, $allowedOrigins, true), $articleHandler, $videoIntake, $wordpressAttachments, $categoryGateway, $draftGateway)))->register();
            do_action('nhk_mcp_register_tools', McpToolCatalog::tools(), $mcpRead, $mcpGovernance);
        });
        add_action('admin_menu', [AdminPage::class, 'register']);
    }
    public static function activate(): void {
        add_option('nhk_core_migration_current', 0, '', false);
        add_option('nhk_core_migration_target', MediaWordPressBridgeMigration012::VERSION, '', false);
        (new GraphMigration001())->up();
        (new AuthorityMigration002())->up();
        (new GovernanceMigration003())->up();
        (new MediaMigration004())->up();
        (new KnowledgeMigration005())->up();
        (new MigrationLedger006())->up();
        (new KnowledgeEvidenceMetadataMigration007())->up();
        (new MediaAssetMetadataMigration008())->up();
        (new ProjectionContextMigration009())->up();
        (new ArticleIngestMigration010())->up();
        (new ArticleMediaMigration011())->up();
        (new MediaWordPressBridgeMigration012())->up();
        (new OwnerPublicationDecisionMigration013())->up();
        GovernanceCapabilities::register();
        flush_rewrite_rules(false);
    }
    public static function deactivate(): void {}
}
