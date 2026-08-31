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
use NHK\Core\Application\Governance\GovernanceCapabilities;
use NHK\Core\Infrastructure\Http\ReadApi;
use NHK\Core\Infrastructure\Http\GovernanceApi;
use NHK\Core\Infrastructure\Http\SearchApi;
use NHK\Core\Infrastructure\Admin\AdminPage;
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;

final class Plugin {
    public static function boot(string $pluginFile): void {
        // Keep an already-installed site aware of the code's migration target;
        // activation is not required for an upgrade health check to be honest.
        update_option('nhk_core_migration_target', KnowledgeMigration005::VERSION, false);
        // Register capabilities on every load so existing installations and
        // upgrades do not need a deactivate/activate cycle to authorize P4.
        GovernanceCapabilities::register();
        add_action('rest_api_init', static function (): void {
            (new HealthCheck(new MigrationStatus()))->register_routes();
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) return;
            (new ReadApi(new WpdbMediaRepository($wpdb), new WpdbMediaAssetRepository($wpdb), new WpdbMediaUsageRepository($wpdb), new WpdbVideoRepository($wpdb), new WpdbKnowledgeRepository($wpdb), new WpdbSourceRepository($wpdb), new WpdbEvidenceRepository($wpdb), new MigrationStatus()))->register();
            (new GovernanceApi())->register();
            $types = new EntityTypeRegistry();
            CanonicalEntityTypeCatalog::registerInto($types);
            (new SearchApi(new WpdbMediaRepository($wpdb), new WpdbVideoRepository($wpdb), new WpdbKnowledgeRepository($wpdb), new WpdbAuthorityRepository($wpdb), $types, new MigrationStatus()))->register();
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
    }
    public static function deactivate(): void {}
}
