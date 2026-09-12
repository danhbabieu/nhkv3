<?php
declare(strict_types=1);

use NHK\Core\Shared\Health\HealthCheck;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Plugin;
use NHK\Core\Infrastructure\Maintenance\MaintenanceCapabilityBridge;
use NHK\Core\Application\Collector\CollectorFacetMaintenanceService;
use NHK\Core\Application\Snapshot\{CanonicalSnapshotExportService, CanonicalSnapshotImportService, RecoveryRuntimeGuard, SnapshotArtifactCodec};
use NHK\Core\Contracts\Snapshot\{CanonicalSnapshotSource, CanonicalSnapshotWriter};

$operation = null;
$json = false;
$pack = '';
$runId = '';
$sourceRevision = '';
$classification = '';
$apply = false;
$dryRun = false;
$proposalId = '';
$input = '';
$output = '';
$recoveryMode = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--json') { $json = true; continue; }
    if (str_starts_with($argument, '--operation=')) { $operation = substr($argument, 12); continue; }
    if (str_starts_with($argument, '--pack=')) { $pack = substr($argument, 7); continue; }
    if (str_starts_with($argument, '--run-id=')) { $runId = substr($argument, 9); continue; }
    if (str_starts_with($argument, '--source-revision=')) { $sourceRevision = substr($argument, 18); continue; }
    if (str_starts_with($argument, '--classification=')) { $classification = substr($argument, 16); continue; }
    if (str_starts_with($argument, '--proposal-id=')) { $proposalId = substr($argument, 14); continue; }
    if (str_starts_with($argument, '--input=')) { $input = substr($argument, 8); continue; }
    if (str_starts_with($argument, '--output=')) { $output = substr($argument, 9); continue; }
    if ($argument === '--recovery-mode') { $recoveryMode = true; continue; }
    if ($argument === '--dry-run') { $dryRun = true; continue; }
    if ($argument === '--apply') { $apply = true; continue; }
    fwrite(STDERR, "UNKNOWN_ARGUMENT\n"); exit(64);
}
$allowed = ['health', 'inventory', 'canonical-inventory', 'graph-inventory', 'relation-dry-run', 'migration-up', 'dry-run', 'backup/snapshot', 'v3-snapshot-export', 'v3-snapshot-import', 'governance-plan', 'controlled-apply', 'read-back', 'collector-facet-maintenance'];
if (!is_string($operation) || !in_array($operation, $allowed, true)) {
    $payload = ['status' => 'blocked', 'reason_code' => 'REMOTE_OPERATION_NOT_ALLOWLISTED'];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}
if (!in_array($operation, ['collector-facet-maintenance', 'v3-snapshot-export', 'v3-snapshot-import'], true) && ($pack === '' || $runId === '' || $sourceRevision === '')) {
    $payload = ['status' => 'blocked', 'reason_code' => 'MAINTENANCE_CONTEXT_REQUIRED'];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

$wordpressRoot = dirname(__DIR__, 4);
$wpLoad = $wordpressRoot . '/wp-load.php';
if (!is_readable($wpLoad)) {
    echo json_encode(['status' => 'blocked', 'reason_code' => 'WORDPRESS_BOOTSTRAP_UNAVAILABLE'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}
try {
    require_once $wpLoad;
    if ($operation === 'collector-facet-maintenance') {
        if ($classification === '' && !$apply) throw new \RuntimeException('CLASSIFICATION_UUID_REQUIRED');
        if ($apply && $proposalId === '') throw new \RuntimeException('APPROVED_PROPOSAL_ID_REQUIRED');
        do_action('rest_api_init');
        $service = apply_filters('nhk_v3_collector_facet_maintenance_service', null);
        if (!$service instanceof CollectorFacetMaintenanceService) throw new \RuntimeException('COLLECTOR_FACET_RUNTIME_UNAVAILABLE');
        if ($apply) {
            $results = $service->applyBatch([$proposalId]);
            $payload = ['status' => (($results[0]['status'] ?? '') === 'applied') ? 'pass' : 'failed', 'apply' => $results, 'proposal_id' => $proposalId];
        } else {
            $payload = $service->plan($classification);
            $payload['dry_run'] = true;
        }
    } elseif ($operation === 'v3-snapshot-export') {
        if ($output === '') throw new \RuntimeException('SNAPSHOT_OUTPUT_PATH_REQUIRED');
        do_action('rest_api_init');
        $source = apply_filters('nhk_v3_snapshot_source', null);
        if (!$source instanceof CanonicalSnapshotSource) throw new \RuntimeException('SNAPSHOT_SOURCE_ADAPTER_UNAVAILABLE');
        $snapshot = (new CanonicalSnapshotExportService())->export($source);
        $payload = [
            'status' => 'pass', 'identifier' => 'v3-snapshot-export',
            'manifest' => $snapshot->manifest, 'snapshot_path' => $output,
            'snapshot_sha256' => SnapshotArtifactCodec::write($output, $snapshot),
        ];
    } elseif ($operation === 'v3-snapshot-import') {
        if ($input === '') throw new \RuntimeException('SNAPSHOT_INPUT_PATH_REQUIRED');
        if (!is_readable($input)) throw new \RuntimeException('SNAPSHOT_INPUT_UNREADABLE');
        $snapshot = SnapshotArtifactCodec::decode((string) file_get_contents($input));
        $allowedDatabases = getenv('NHK_RECOVERY_ALLOWED_DATABASES');
        $allowedDatabases = is_string($allowedDatabases) && $allowedDatabases !== '' ? array_values(array_filter(array_map('trim', explode(',', $allowedDatabases)))) : [];
        $writer = apply_filters('nhk_v3_snapshot_writer', null);
        if (!$writer instanceof CanonicalSnapshotWriter) throw new \RuntimeException('SNAPSHOT_WRITER_ADAPTER_UNAVAILABLE');
        $receipt = (new CanonicalSnapshotImportService(new RecoveryRuntimeGuard($allowedDatabases)))->import($snapshot, $writer, $recoveryMode);
        $payload = ['status' => 'pass', 'identifier' => 'v3-snapshot-import', 'receipt' => $receipt, 'snapshot_path' => $input];
    } elseif ($operation === 'migration-up') {
        Plugin::runPendingMigrations();
        $payload = ['status' => 'pass', 'identifier' => 'remote-migration-up', 'current' => (int) get_option('nhk_core_migration_current', 0), 'target' => (int) get_option('nhk_core_migration_target', 0), 'pack' => $pack, 'run_id' => $runId, 'source_revision' => $sourceRevision];
    } elseif (in_array($operation, ['canonical-inventory', 'graph-inventory', 'relation-dry-run'], true)) {
        do_action('rest_api_init');
        $request = new \WP_REST_Request('POST', '/nhk/v1/mcp');
        $request->set_header('Content-Type', 'application/json');
        $arguments = $operation === 'relation-dry-run' ? ['records' => []] : ['filters' => [], 'limit' => 100];
        $result = MaintenanceCapabilityBridge::call($operation, $arguments, static function (string $tool, array $input): array {
            $request = new \WP_REST_Request('POST', '/nhk/v1/mcp');
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $input]]));
            $response = rest_do_request($request);
            if (is_wp_error($response)) throw new \RuntimeException('MAINTENANCE_MCP_REQUEST_FAILED');
            $body = $response->get_data();
            $result = is_array($body) ? ($body['result'] ?? null) : null;
            if (!is_array($result) || ($result['isError'] ?? false) === true) throw new \RuntimeException('MAINTENANCE_MCP_CAPABILITY_FAILED');
            $content = $result['structuredContent'] ?? null;
            if (!is_array($content)) throw new \RuntimeException('MAINTENANCE_MCP_INVALID_RECEIPT');
            return $content;
        });
        $payload = ['status' => ($result['status'] ?? null) === 'available' ? 'pass' : 'blocked', 'identifier' => 'remote-' . $operation, 'capability' => $result, 'pack' => $pack, 'run_id' => $runId, 'source_revision' => $sourceRevision];
        if ($payload['status'] !== 'pass') $payload['reason_code'] = 'MAINTENANCE_CAPABILITY_UNAVAILABLE';
    } elseif ($operation === 'health') {
        $payload = (new HealthCheck(new MigrationStatus()))->read();
        $ok = ($payload['layers']['storage']['ok'] ?? false) && ($payload['layers']['application']['ok'] ?? false);
        $payload = ['status' => $ok ? 'pass' : 'blocked', 'identifier' => 'remote-health', 'health' => $payload];
        if (!$ok) $payload['reason_code'] = 'REMOTE_HEALTH_NOT_READY';
    } elseif ($operation === 'inventory') {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || empty($wpdb->dbh)) {
            $payload = ['status' => 'blocked', 'reason_code' => 'DATABASE_UNREACHABLE'];
        } else {
            $tables = [
                'nhk_entities', 'nhk_graph_nodes', 'nhk_graph_edges', 'nhk_graph_predicates',
                'nhk_knowledge_claims', 'nhk_sources', 'nhk_evidence', 'nhk_media',
                'nhk_media_assets', 'nhk_media_usages', 'nhk_videos', 'nhk_proposals',
                'nhk_proposal_dependencies', 'nhk_proposal_approvals', 'nhk_apply_attempts',
                'nhk_audit_events', 'posts', 'postmeta', 'terms', 'term_taxonomy', 'termmeta', 'options',
            ];
            $inventory = [];
            foreach ($tables as $name) {
                $table = $wpdb->prefix . $name;
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) continue;
                $rows = $wpdb->get_results('SELECT * FROM ' . $table, ARRAY_A) ?: [];
                $safeRows = array_map(static function (array $row) use ($name): array {
                    if ($name === 'options' && preg_match('/(password|secret|token|key|credential)/i', (string) ($row['option_name'] ?? ''))) {
                        $row['option_value'] = '[REDACTED]';
                    }
                    foreach ($row as $key => $value) {
                        if (preg_match('/(password|secret|token|private[_-]?key|authorization)/i', (string) $key)) $row[$key] = '[REDACTED]';
                    }
                    return $row;
                }, $rows);
                $inventory[$name] = ['table' => $table, 'count' => count($safeRows), 'rows' => $safeRows];
            }
            $receipt = [
                'status' => 'pass', 'identifier' => 'remote-' . ($operation === 'inventory' ? 'inventory' : 'snapshot'),
                'target' => (string) site_url(), 'pack' => $pack, 'run_id' => $runId,
                'source_revision' => $sourceRevision, 'database' => (string) $wpdb->get_var('SELECT DATABASE()'),
                'inventory' => $inventory,
            ];
            $payload = $receipt;
        }
    } elseif ($operation === 'backup/snapshot') {
        $payload = ['status' => 'blocked', 'reason_code' => 'LEGACY_RAW_SNAPSHOT_RETIRED', 'replacement' => 'v3-snapshot-export'];
    } else {
        // The read/planning/apply implementations are deliberately composed
        // in the application layer; this entrypoint never accepts SQL or PHP
        // code and never invents a second mutation path.
        $payload = ['status' => 'blocked', 'reason_code' => 'CUTOVER_APPLICATION_WIRING_REQUIRED', 'operation' => $operation];
    }
} catch (Throwable $error) {
    $reason = in_array($operation, ['v3-snapshot-export', 'v3-snapshot-import'], true)
        ? (string) $error->getMessage()
        : 'REMOTE_RUNTIME_BOOTSTRAP_FAILED';
    $payload = ['status' => 'failed', 'reason_code' => $reason !== '' ? $reason : 'REMOTE_RUNTIME_BOOTSTRAP_FAILED'];
}
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit(($payload['status'] ?? '') === 'pass' ? 0 : 2);
