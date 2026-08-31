<?php
declare(strict_types=1);

/**
 * Apply a previously exported V2 record set through the governed, resumable
 * migration service. Without --apply this is a no-write plan summary.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use NHK\Core\Application\Migration\V2MigrationService;
use NHK\Core\Infrastructure\Migration\MigrationLedger006;

$input = null;
$limit = 100;
$batch = 1;
$offset = 0;
$apply = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--input=')) $input = substr($argument, 8);
    elseif (str_starts_with($argument, '--limit=')) $limit = max(1, (int) substr($argument, 8));
    elseif (str_starts_with($argument, '--batch=')) $batch = max(1, (int) substr($argument, 8));
    elseif (str_starts_with($argument, '--offset=')) $offset = max(0, (int) substr($argument, 9));
    elseif ($argument === '--apply') $apply = true;
}
if ($input === null || $input === '' || ($input !== '-' && !is_readable($input))) {
    fwrite(STDERR, "Usage: php tools/v2-migrate.php --input=/path/to/export.json [--offset=0] [--limit=100] [--batch=1] [--apply]\n");
    exit(2);
}
$decoded = json_decode((string) file_get_contents($input === '-' ? 'php://stdin' : $input), true);
if (!is_array($decoded) || !is_array($decoded['records'] ?? null)) {
    fwrite(STDERR, "Input must be JSON object with a records array.\n");
    exit(2);
}
$records = $decoded['records'];

if (!$apply) {
    $byType = [];
    foreach ($records as $record) {
        if (!is_array($record)) continue;
        $type = (string) ($record['type'] ?? 'unknown');
        $byType[$type] = ($byType[$type] ?? 0) + 1;
    }
    ksort($byType);
    echo json_encode(['mode' => 'plan', 'source_records' => count($records), 'offset' => $offset, 'batch' => $batch, 'limit' => $limit, 'by_type' => $byType], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if (getenv('NHK_V3_MIGRATION_APPLY') !== '1') {
    fwrite(STDERR, "Refusing apply: set NHK_V3_MIGRATION_APPLY=1 after reviewing the backup/restore gate.\n");
    exit(3);
}

require_once dirname(__DIR__) . '/public/wp-load.php';
global $wpdb;
if (!isset($wpdb) || !is_object($wpdb)) {
    fwrite(STDERR, "WordPress database is unavailable.\n");
    exit(3);
}
$database = (string) $wpdb->get_var('SELECT DATABASE()');
if (!in_array($database, ['nhk_v3_test', 'nhk_v3'], true)) {
    fwrite(STDERR, "Refusing apply on database {$database}; target must be nhk_v3_test or nhk_v3.\n");
    exit(3);
}
(new MigrationLedger006())->up();
$result = (new V2MigrationService($wpdb))->apply(array_slice($records, $offset), $batch, $limit);
echo json_encode(['mode' => 'apply', 'database' => $database, 'offset' => $offset, 'batch' => $batch, 'limit' => $limit, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
