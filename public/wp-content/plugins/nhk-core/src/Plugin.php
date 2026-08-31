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
use NHK\Core\Application\Governance\{AuthorityProposalExecutor, GovernanceCapabilities, GovernanceService, ProposalEligibilityService, WordPressGovernanceAuthorizer};
use NHK\Core\Application\Governance\ControlledApplyService;
use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpReadHandler, McpToolCatalog};
use NHK\Core\Infrastructure\Http\ReadApi;
use NHK\Core\Infrastructure\Http\GovernanceApi;
use NHK\Core\Infrastructure\Http\SearchApi;
use NHK\Core\Infrastructure\Http\EntityApi;
use NHK\Core\Infrastructure\Http\GraphApi;
use NHK\Core\Infrastructure\Http\PublicMediaVideoRoutes;
use NHK\Core\Infrastructure\Http\PublicEntityRoutes;
use NHK\Core\Infrastructure\Admin\AdminPage;
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, WpdbAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Governance\{NoOpApplyExecutionHook, WpdbApplyAttemptRepository, WpdbDependencyRepository, WpdbEligibilityReader, WpdbProposalRepository};
use NHK\Core\Domain\Governance\DependencyGraph;
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Application\Entity\{EntityPageQuery, RelatedContentQuery};
use NHK\Core\Application\Media\MediaVideoPageQuery;

final class Plugin {
    public static function boot(string $pluginFile): void {
        // Keep an already-installed site aware of the code's migration target;
        // activation is not required for an upgrade health check to be honest.
        update_option('nhk_core_migration_target', KnowledgeMigration005::VERSION, false);
        // Register capabilities on every load so existing installations and
        // upgrades do not need a deactivate/activate cycle to authorize P4.
        GovernanceCapabilities::register();
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) { $publicTypes = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($publicTypes); $publicAuthority = new WpdbAuthorityRepository($wpdb); $publicMedia = new WpdbMediaRepository($wpdb); $publicVideos = new WpdbVideoRepository($wpdb); $publicEndpoints = new EndpointTypeRegistry(); CoreEndpointResolverRegistrar::register($publicEndpoints, $publicTypes, $publicAuthority, $publicMedia, $publicVideos); $publicStatus = new MigrationStatus(); $publicGraph = new GraphService(new WpdbGraphRepository($wpdb), $publicEndpoints, new PredicateRegistry(), new WpdbAuditSink()); $publicRelated = new RelatedContentQuery($publicGraph, $publicAuthority, $publicMedia, $publicVideos, $publicTypes, $publicStatus); (new PublicEntityRoutes(new EntityPageQuery($publicAuthority, $publicTypes, $publicRelated, $publicStatus), $publicTypes))->register(); $publicAssets = new WpdbMediaAssetRepository($wpdb); $publicUsages = new WpdbMediaUsageRepository($wpdb); (new PublicMediaVideoRoutes(new MediaVideoPageQuery($publicMedia, $publicAssets, $publicUsages, $publicVideos, $publicStatus)))->register(); }
        add_action('rest_api_init', static function (): void {
            (new HealthCheck(new MigrationStatus()))->register_routes();
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) return;
            $media = new WpdbMediaRepository($wpdb); $assets = new WpdbMediaAssetRepository($wpdb); $usages = new WpdbMediaUsageRepository($wpdb); $videos = new WpdbVideoRepository($wpdb); $claims = new WpdbKnowledgeRepository($wpdb); $sources = new WpdbSourceRepository($wpdb); $evidence = new WpdbEvidenceRepository($wpdb); $authority = new WpdbAuthorityRepository($wpdb);
            (new ReadApi($media, $assets, $usages, $videos, $claims, $sources, $evidence, new MigrationStatus()))->register();
            $types = new EntityTypeRegistry();
            CanonicalEntityTypeCatalog::registerInto($types);
            $endpoints = new EndpointTypeRegistry(); CoreEndpointResolverRegistrar::register($endpoints, $types, $authority, $media, $videos, $claims, $sources, $evidence); $graphRepository = new WpdbGraphRepository($wpdb); $graphService = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new WpdbAuditSink());
            $proposalRepository = new WpdbProposalRepository($wpdb); $governanceAudit = new \NHK\Core\Infrastructure\Governance\WpdbAuditSink($wpdb); $transactionManager = new WpdbTransactionManager($wpdb); $governance = new GovernanceService($proposalRepository, $governanceAudit, $transactionManager, new WordPressGovernanceAuthorizer());
            $eligibility = new ProposalEligibilityService($proposalRepository, new DependencyGraph(new WpdbDependencyRepository($wpdb)), new WpdbEligibilityReader($authority, $proposalRepository, $graphRepository));
            $authorityService = new \NHK\Core\Application\Authority\AuthorityService($authority, $types, new \NHK\Core\Infrastructure\Authority\WpdbAuditSink($wpdb));
            $controlledApply = new ControlledApplyService($proposalRepository, new WpdbApplyAttemptRepository($wpdb), $transactionManager, new AuthorityProposalExecutor($authorityService, $graphService), $governanceAudit, $eligibility, new NoOpApplyExecutionHook(), new WordPressGovernanceAuthorizer());
            (new GovernanceApi($governance, $eligibility, $controlledApply))->register();
            (new SearchApi($media, $videos, $claims, $authority, $types, new MigrationStatus()))->register();
            (new EntityApi($authority, $types))->register();
            (new GraphApi($graphService, new MigrationStatus()))->register();
            do_action('nhk_mcp_register_tools', McpToolCatalog::tools(), new McpReadHandler($authority, $types, $media, $assets, $usages, $videos, $claims, $evidence, new MigrationStatus()), new McpGovernanceHandler($governance, $eligibility, $controlledApply));
        });
        add_action('admin_menu', [AdminPage::class, 'register']);
    }
    public static function activate(): void {
        add_option('nhk_core_migration_current', 0, '', false);
        add_option('nhk_core_migration_target', 5, '', false);
        (new GraphMigration001())->up();
        (new AuthorityMigration002())->up();
        (new GovernanceMigration003())->up();
        (new MediaMigration004())->up();
        (new KnowledgeMigration005())->up();
        GovernanceCapabilities::register();
        flush_rewrite_rules(false);
    }
    public static function deactivate(): void {}
}
