<?php
declare(strict_types=1);

use NHK\Core\Shared\Health\HealthCheck;
use NHK\Core\Shared\Migration\MigrationStatus;

$operation = null;
$json = false;
$pack = '';
$runId = '';
$sourceRevision = '';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--json') { $json = true; continue; }
    if (str_starts_with($argument, '--operation=')) { $operation = substr($argument, 12); continue; }
    if (str_starts_with($argument, '--pack=')) { $pack = substr($argument, 7); continue; }
    if (str_starts_with($argument, '--run-id=')) { $runId = substr($argument, 9); continue; }
    if (str_starts_with($argument, '--source-revision=')) { $sourceRevision = substr($argument, 18); continue; }
    fwrite(STDERR, "UNKNOWN_ARGUMENT\n"); exit(64);
}
$allowed = ['health', 'inventory', 'dry-run', 'backup/snapshot', 'governance-plan', 'controlled-apply', 'read-back'];
if (!is_string($operation) || !in_array($operation, $allowed, true)) {
    $payload = ['status' => 'blocked', 'reason_code' => 'REMOTE_OPERATION_NOT_ALLOWLISTED'];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}
if ($pack === '' || $runId === '' || $sourceRevision === '') {
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
    if ($operation === 'health') {
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
                    $contents = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
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
echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($payload['status'] ?? '') === 'pass' ? 0 : 2);
