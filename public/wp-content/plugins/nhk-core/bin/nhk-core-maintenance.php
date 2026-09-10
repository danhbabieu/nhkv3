<?php
declare(strict_types=1);

use NHK\Core\Shared\Health\HealthCheck;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Plugin;
use NHK\Core\Infrastructure\Maintenance\MaintenanceCapabilityBridge;
use NHK\Core\Application\Collector\CollectorFacetMaintenanceService;

$operation = null;
$json = false;
$pack = '';
$runId = '';
$sourceRevision = '';
$classification = '';
$apply = false;
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--json') { $json = true; continue; }
    if (str_starts_with($argument, '--operation=')) { $operation = substr($argument, 12); continue; }
    if (str_starts_with($argument, '--pack=')) { $pack = substr($argument, 7); continue; }
    if (str_starts_with($argument, '--run-id=')) { $runId = substr($argument, 9); continue; }
    if (str_starts_with($argument, '--source-revision=')) { $sourceRevision = substr($argument, 18); continue; }
    if (str_starts_with($argument, '--classification=')) { $classification = substr($argument, 16); continue; }
    if ($argument === '--dry-run') { $dryRun = true; continue; }
    if ($argument === '--apply') { $apply = true; continue; }
    fwrite(STDERR, "UNKNOWN_ARGUMENT\n"); exit(64);
}
$allowed = ['health', 'inventory', 'canonical-inventory', 'graph-inventory', 'relation-dry-run', 'migration-up', 'dry-run', 'backup/snapshot', 'governance-plan', 'controlled-apply', 'read-back', 'collector-facet-maintenance'];
if (!is_string($operation) || !in_array($operation, $allowed, true)) {
    $payload = ['status' => 'blocked', 'reason_code' => 'REMOTE_OPERATION_NOT_ALLOWLISTED'];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}
if ($operation !== 'collector-facet-maintenance' && ($pack === '' || $runId === '' || $sourceRevision === '')) {
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
        if ($classification === '') throw new \RuntimeException('CLASSIFICATION_UUID_REQUIRED');
        if ($apply) throw new \RuntimeException('COLLECTOR_FACET_APPLY_REQUIRES_APPROVED_PROPOSAL');
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || empty($wpdb->dbh)) throw new \RuntimeException('DATABASE_UNREACHABLE');
        $types = new \NHK\Core\Domain\Authority\EntityTypeRegistry();
        \NHK\Core\Domain\Authority\CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new \NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository($wpdb);
        $claims = new \NHK\Core\Infrastructure\Knowledge\WpdbKnowledgeRepository($wpdb);
        $sources = new \NHK\Core\Infrastructure\Knowledge\WpdbSourceRepository($wpdb);
        $evidence = new \NHK\Core\Infrastructure\Knowledge\WpdbEvidenceRepository($wpdb);
        $endpoints = new \NHK\Core\Domain\Graph\EndpointTypeRegistry();
        \NHK\Core\Infrastructure\Graph\CoreEndpointResolverRegistrar::register($endpoints, $types, $authority, new \NHK\Core\Infrastructure\Media\WpdbMediaRepository($wpdb), new \NHK\Core\Infrastructure\Video\WpdbVideoRepository($wpdb), $claims, $sources, $evidence);
        $graph = new \NHK\Core\Application\Graph\GraphService(new \NHK\Core\Infrastructure\Graph\WpdbGraphRepository($wpdb), $endpoints, new \NHK\Core\Domain\Graph\PredicateRegistry(), new \NHK\Core\Infrastructure\Graph\WpdbAuditSink());
        $branchReader = static function (string $classificationId) use ($authority, $claims, $graph): array {
            $entity = $authority->findByCanonicalId($classificationId);
            if (!$entity instanceof \NHK\Core\Domain\Authority\AuthorityEntity || $entity->entityType !== 'classification' || !$entity->active()) return ['status' => 'unavailable', 'reason' => 'CLASSIFICATION_NOT_AVAILABLE'];
            $items = []; $after = 0;
            try {
                do {
                    $page = $graph->findIncoming(new \NHK\Core\Domain\Graph\NodeReference('classification', $classificationId), 'about', $after, 200, false, 'knowledge');
                    foreach ((array) ($page['items'] ?? []) as $edge) if ($edge instanceof \NHK\Core\Domain\Graph\GraphEdge && $edge->isActive()) {
                        $claim = $claims->findByCanonicalId($edge->source->reference->endpoint_key);
                        if ($claim !== null && $claim->active && $claim->isPublic()) $items[$claim->canonicalId] = $claim;
                    }
                    $next = $page['next_cursor'] ?? null;
                    if ($next === null) break;
                    if (!is_int($next) || $next <= $after) return ['status' => 'unavailable', 'reason' => 'BRANCH_KNOWLEDGE_CURSOR_INVALID'];
                    $after = $next;
                } while (true);
            } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'BRANCH_KNOWLEDGE_UNAVAILABLE']; }
            return ['status' => 'available', 'claims' => array_values($items), 'classification_revision' => $entity->revision];
        };
        $payload = (new CollectorFacetMaintenanceService($claims, $branchReader))->plan($classification);
        $payload['dry_run'] = true;
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
    } elseif ($operation === 'inventory' || $operation === 'backup/snapshot') {
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
            if ($operation === 'backup/snapshot') {
                $snapshotRoot = getenv('NHK_CUTOVER_SNAPSHOT_ROOT');
                $snapshotRoot = is_string($snapshotRoot) && $snapshotRoot !== '' ? rtrim($snapshotRoot, '/') : dirname($wordpressRoot) . '/nhk-cutover-snapshots';
                if (!is_dir($snapshotRoot) && !mkdir($snapshotRoot, 0700, true) && !is_dir($snapshotRoot)) {
                    $payload = ['status' => 'blocked', 'reason_code' => 'SNAPSHOT_PATH_UNAVAILABLE'];
                } else {
                    $stamp = gmdate('Ymd\THis\Z');
                    $path = $snapshotRoot . '/odo-' . $stamp . '-' . substr(hash('sha256', $runId), 0, 12) . '.json';
                    $contents = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
                    if (file_put_contents($path, $contents, LOCK_EX) === false) {
                        $payload = ['status' => 'blocked', 'reason_code' => 'SNAPSHOT_WRITE_FAILED'];
                    } else {
                        $receipt['snapshot_path'] = $path;
                        $receipt['snapshot_sha256'] = hash_file('sha256', $path);
                        $payload = $receipt;
                    }
                }
            } else $payload = $receipt;
        }
    } else {
        // The read/planning/apply implementations are deliberately composed
        // in the application layer; this entrypoint never accepts SQL or PHP
        // code and never invents a second mutation path.
        $payload = ['status' => 'blocked', 'reason_code' => 'CUTOVER_APPLICATION_WIRING_REQUIRED', 'operation' => $operation];
    }
} catch (Throwable $error) {
    $payload = ['status' => 'failed', 'reason_code' => 'REMOTE_RUNTIME_BOOTSTRAP_FAILED'];
}
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit(($payload['status'] ?? '') === 'pass' ? 0 : 2);
