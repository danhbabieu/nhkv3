<?php
declare(strict_types=1);
namespace NHK\Core\Shared\Health;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Application\Authority\AuthorityParityAudit;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;

final class HealthCheck {
    public function __construct(private object $migrations, private $hydrationProbe = null) {}
    public function register_routes(): void {
        register_rest_route('nhk/v1', '/health', [
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => fn () => $this->read(),
        ]);
    }
    public function read(): array {
        global $wpdb;
        $migration = $this->migrations->status();
        $database = isset($wpdb) && is_object($wpdb) && !empty($wpdb->dbh);
        $storage = ['ok' => $database && $migration['current'] >= $migration['target'], 'reason_code' => !$database ? 'DATABASE_UNREACHABLE' : ($migration['current'] < $migration['target'] ? 'MIGRATION_REQUIRED' : null)];
        $runtime = $this->runtimeLayer();
        $hydration = $this->hydrationLayer();
        $application = ['ok' => $hydration['ok'], 'reason_code' => $hydration['ok'] ? null : $hydration['reason_code']];
        $rest = ['ok' => function_exists('rest_get_server'), 'reason_code' => function_exists('rest_get_server') ? null : 'REST_BOOTSTRAP_UNAVAILABLE'];
        return [
            'plugin_version' => defined('NHK_CORE_VERSION') ? NHK_CORE_VERSION : 'unknown', 'api_version' => defined('NHK_CORE_API_VERSION') ? NHK_CORE_API_VERSION : 'unknown',
            'database_reachable' => $database,
            'migration_current' => $migration['current'], 'migration_target' => $migration['target'],
            'migration_required' => $migration['current'] < $migration['target'],
            'graph_storage_ready' => $this->migrations->graphStorageReady(),
            'authority_storage_ready' => $this->migrations->authorityStorageReady(),
            'governance_storage_ready' => $this->migrations->governanceStorageReady(),
            'media_storage_ready' => $this->migrations->mediaStorageReady(),
            'video_storage_ready' => $this->migrations->videoStorageReady(),
            'knowledge_storage_ready' => $this->migrations->knowledgeStorageReady(),
            'layers' => ['storage' => $storage, 'runtime' => $runtime, 'hydration' => $hydration, 'application' => $application, 'rest' => $rest],
        ];
    }

    /** @return array{ok:bool,reason_code:?string} */
    private function runtimeLayer(): array
    {
        if (!is_readable(dirname(__DIR__, 7) . '/vendor/autoload.php')) return ['ok' => false, 'reason_code' => 'COMPOSER_AUTOLOAD_MISSING'];
        if (!class_exists('Symfony\\Component\\Uid\\Uuid')) return ['ok' => false, 'reason_code' => 'SYMFONY_UID_UNAVAILABLE'];
        if (!class_exists(WpdbAuthorityRepository::class)) return ['ok' => false, 'reason_code' => 'NHK_RUNTIME_CLASS_UNAVAILABLE'];
        return ['ok' => true, 'reason_code' => null];
    }

    /** @return array{ok:bool,reason_code:?string} */
    private function hydrationLayer(): array
    {
        if (is_callable($this->hydrationProbe)) {
            $result = ($this->hydrationProbe)();
            return ['ok' => ($result['status'] ?? '') !== 'HYDRATION_LOSS', 'reason_code' => $result['reason_code'] ?? null];
        }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return ['ok' => false, 'reason_code' => 'DATABASE_UNREACHABLE'];
        try {
            $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
            $audit = new AuthorityParityAudit(static fn (string $type): int => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'nhk_entities WHERE entity_type=%s', $type)));
            foreach ($audit->run($types, new WpdbAuthorityRepository()) as $row) if ($row['status'] === 'HYDRATION_LOSS') return ['ok' => false, 'reason_code' => 'HYDRATION_LOSS'];
            return ['ok' => true, 'reason_code' => null];
        } catch (\RuntimeException) {
            return ['ok' => false, 'reason_code' => 'HYDRATION_RUNTIME_FAILURE'];
        }
    }
}
