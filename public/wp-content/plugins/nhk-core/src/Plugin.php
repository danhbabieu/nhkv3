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
use NHK\Core\Application\Governance\{AuthorityProposalExecutor, GovernanceCapabilities, GovernanceService, ProposalEligibilityService, WordPressGovernanceAuthorizer};
use NHK\Core\Application\Governance\ControlledApplyService;
use NHK\Core\Application\Mcp\{McpAbilityRegistration, McpArticleIngestHandler, McpGovernanceHandler, McpReadHandler, McpSemanticContextResolver, McpToolCatalog, McpTransport};
use NHK\Core\Application\Article\{ArticleIngestCoordinator, ArticleIngestPreflight, ArticleVerificationReader, SemanticProposalPlanner};
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
use NHK\Core\Infrastructure\Http\McpApi;
use NHK\Core\Infrastructure\Admin\AdminPage;
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Article\{WpEditorialStateReader, WpdbArticleOperationReceiptRepository};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Application\Graph\{BrandAggregationQuery, GraphService, StructuralContextQuery};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, WpdbAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Governance\{NoOpApplyExecutionHook, WpdbApplyAttemptRepository, WpdbDependencyRepository, WpdbEligibilityReader, WpdbProposalRepository};
use NHK\Core\Domain\Governance\DependencyGraph;
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Application\Entity\{ComparisonPageQuery, EntityPageQuery, PublicEntityCollectionQuery, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver, RelatedContentQuery};
use NHK\Core\Application\Media\{MediaService, MediaVideoPageQuery};
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Application\Home\HomeSemanticQuery;
use NHK\Core\Application\Search\SearchSemanticQuery;
use NHK\Core\Application\Knowledge\KnowledgePageQuery;
use NHK\Core\Application\Knowledge\KnowledgeService;

final class Plugin {
    private const REWRITE_VERSION = '8';
    public static function boot(string $pluginFile): void {
        // Keep an already-installed site aware of the code's migration target;
        // activation is not required for an upgrade health check to be honest.
        update_option('nhk_core_migration_target', ArticleIngestMigration010::VERSION, false);
        if ((int) get_option('nhk_core_migration_current', 0) < ArticleIngestMigration010::VERSION) (new ArticleIngestMigration010())->up();
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
        });
        (new PublicEditorialRoutes())->register();
        LegacyUrlRedirects::register();
        global $wpdb;
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
            (new PublicMediaVideoRoutes(new MediaVideoPageQuery($publicMedia, $publicAssets, $publicUsages, $publicVideos, $publicStatus)))->register();
            $mediaRoot = defined('NHK_MEDIA_STORAGE_ROOT') ? (string) NHK_MEDIA_STORAGE_ROOT : (string) (getenv('NHK_MEDIA_STORAGE_ROOT') ?: '');
            if ($mediaRoot === '' && function_exists('wp_upload_dir')) { $upload = wp_upload_dir(); $mediaRoot = is_array($upload) ? (string) ($upload['basedir'] ?? '') : ''; }
            (new PublicMediaAssetRoutes(new \NHK\Core\Application\Media\PublicMediaAssetDelivery($publicAssets, $publicMedia, $mediaRoot)))->register();
            (new PublicKnowledgeRoutes(new KnowledgePageQuery($publicClaims, $publicEvidence, $publicSources, $publicStatus)))->register();
        }
        add_action('rest_api_init', static function (): void {
            (new HealthCheck(new MigrationStatus()))->register_routes();
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) return;
            $media = new WpdbMediaRepository($wpdb); $assets = new WpdbMediaAssetRepository($wpdb); $usages = new WpdbMediaUsageRepository($wpdb); $videos = new WpdbVideoRepository($wpdb); $claims = new WpdbKnowledgeRepository($wpdb); $sources = new WpdbSourceRepository($wpdb); $evidence = new WpdbEvidenceRepository($wpdb); $authority = new WpdbAuthorityRepository($wpdb);
            (new ReadApi($media, $assets, $usages, $videos, $claims, $sources, $evidence, new MigrationStatus()))->register();
            $types = new EntityTypeRegistry();
            CanonicalEntityTypeCatalog::registerInto($types);
            $endpoints = new EndpointTypeRegistry(); CoreEndpointResolverRegistrar::register($endpoints, $types, $authority, $media, $videos, $claims, $sources, $evidence); $graphRepository = new WpdbGraphRepository($wpdb); $graphService = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new WpdbAuditSink());
            $publicStatus = new MigrationStatus();
            $publicContexts = new StructuralContextQuery($graphService, $authority);
            $publicRoutes = new PublicRouteResolver($authority, $types, $publicContexts);
            $publicEligibility = new PublicEntityEligibilityPolicy($authority, $types, $publicRoutes, $publicContexts);
            $publicCollection = new PublicEntityCollectionQuery($authority, $types, new PublicIdentityContract($types), $publicEligibility, $publicRoutes, new BrandAggregationQuery($graphService, $authority, $types, $publicRoutes, $publicEligibility), static fn (): bool => $publicStatus->authorityStorageReady());
            $proposalRepository = new WpdbProposalRepository($wpdb); $governanceAudit = new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb); $transactionManager = new WpdbTransactionManager($wpdb); $governance = new GovernanceService($proposalRepository, $governanceAudit, $transactionManager, new WordPressGovernanceAuthorizer());
            $eligibility = new ProposalEligibilityService($proposalRepository, new DependencyGraph(new WpdbDependencyRepository($wpdb)), new WpdbEligibilityReader($authority, $proposalRepository, $graphRepository, $media, $videos, $claims, $sources, $evidence));
            $authorityService = new \NHK\Core\Application\Authority\AuthorityService($authority, $types, new \NHK\Core\Infrastructure\Authority\WpdbAuditSink(new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb)));
            $controlledApply = new ControlledApplyService($proposalRepository, new WpdbApplyAttemptRepository($wpdb), $transactionManager, new AuthorityProposalExecutor($authorityService, $graphService, new MediaService($media, $assets, $usages), new VideoService($videos), new KnowledgeService($claims, $sources, $evidence)), $governanceAudit, $eligibility, new NoOpApplyExecutionHook(), new WordPressGovernanceAuthorizer());
            $articleEditorial = new WpEditorialStateReader();
            $articlePreflight = new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types, static function (string $type, string $id) use ($authority, $media, $videos, $claims, $sources, $evidence, $graphRepository): bool {
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
            });
            $articleCoordinator = new ArticleIngestCoordinator(new WpdbArticleOperationReceiptRepository($wpdb), $articlePreflight, new SemanticProposalPlanner(), $articleEditorial, $governance, $controlledApply, $proposalRepository, new WpdbDependencyRepository($wpdb), new ArticleVerificationReader());
            $articleHandler = new McpArticleIngestHandler($articleCoordinator, $articlePreflight, $articleEditorial);
            (new GovernanceApi($governance, $eligibility, $controlledApply))->register();
            (new SearchApi($media, $videos, $claims, $authority, $types, $publicStatus, $publicCollection))->register();
            (new EntityApi($authority, $types, $publicStatus, $publicCollection))->register();
            (new GraphApi($graphService, new MigrationStatus()))->register();
            $mcpRead = new McpReadHandler($authority, $types, $media, $assets, $usages, $videos, $claims, $evidence, new MigrationStatus(), $sources, null, new McpSemanticContextResolver($authority, $types));
            $mcpGovernance = new McpGovernanceHandler($governance, $eligibility, $controlledApply);
            $origin = static function (string $value): string { $parts = wp_parse_url($value); if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return ''; return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : ''); };
            $allowedOrigins = array_values(array_filter(array_unique([$origin((string) site_url()), $origin((string) home_url())])));
            (new McpApi(new McpTransport($mcpRead, $mcpGovernance, static fn (string $capability): bool => current_user_can($capability), static fn (string $value): bool => in_array($value, $allowedOrigins, true), $articleHandler)))->register();
            do_action('nhk_mcp_register_tools', McpToolCatalog::tools(), $mcpRead, $mcpGovernance);
        });
        add_action('admin_menu', [AdminPage::class, 'register']);
    }
    public static function activate(): void {
        add_option('nhk_core_migration_current', 0, '', false);
        add_option('nhk_core_migration_target', ArticleIngestMigration010::VERSION, '', false);
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
        GovernanceCapabilities::register();
        flush_rewrite_rules(false);
    }
    public static function deactivate(): void {}
}
