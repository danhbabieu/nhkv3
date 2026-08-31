<?php
declare(strict_types=1);
namespace NHK\Core;
use NHK\Core\Shared\Health\HealthCheck;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Infrastructure\Migration\GraphMigration001;
use NHK\Core\Infrastructure\Migration\AuthorityMigration002;
use NHK\Core\Infrastructure\Migration\GovernanceMigration003;
use NHK\Core\Application\Governance\GovernanceCapabilities;

final class Plugin {
    public static function boot(string $pluginFile): void {
        add_action('rest_api_init', static function (): void {
            (new HealthCheck(new MigrationStatus()))->register_routes();
        });
    }
    public static function activate(): void {
        add_option('nhk_core_migration_current', 0, '', false);
        add_option('nhk_core_migration_target', 3, '', false);
        (new GraphMigration001())->up();
        (new AuthorityMigration002())->up();
        (new GovernanceMigration003())->up();
        GovernanceCapabilities::register();
    }
    public static function deactivate(): void {}
}
