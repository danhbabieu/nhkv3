<?php
declare(strict_types=1);
namespace NHK\Core\Shared\Health;
use NHK\Core\Shared\Migration\MigrationStatus;

final class HealthCheck {
    public function __construct(private MigrationStatus $migrations) {}
    public function register_routes(): void {
        register_rest_route('nhk/v1', '/health', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn () => $this->read(),
        ]);
    }
    public function read(): array {
        global $wpdb;
        $migration = $this->migrations->status();
        return [
            'plugin_version' => NHK_CORE_VERSION, 'api_version' => NHK_CORE_API_VERSION,
            'database_reachable' => isset($wpdb) && is_object($wpdb) && ! empty($wpdb->dbh),
            'migration_current' => $migration['current'], 'migration_target' => $migration['target'],
            'migration_required' => $migration['current'] < $migration['target'],
            'graph_storage_ready' => $this->migrations->graphStorageReady(),
            'authority_storage_ready' => $this->migrations->authorityStorageReady(),
            'governance_storage_ready' => $this->migrations->governanceStorageReady(),
            'media_storage_ready' => $this->migrations->mediaStorageReady(),
            'video_storage_ready' => $this->migrations->videoStorageReady(),
        ];
    }
}
