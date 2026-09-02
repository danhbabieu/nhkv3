<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$format = 'table';
foreach (array_slice($argv, 1) as $argument) if (str_starts_with($argument, '--format=')) $format = strtolower(substr($argument, 9));
if (!in_array($format, ['json', 'table'], true)) { fwrite(STDERR, "Unsupported format. Use --format=json or --format=table.\n"); exit(2); }

try {
    $wpLoad = dirname(__DIR__) . '/public/wp-load.php';
    $probe = [];
    $probeStatus = 1;
    exec(PHP_BINARY . ' -r ' . escapeshellarg('require ' . var_export($wpLoad, true) . '; echo "NHK_WP_OK";') . ' 2>/dev/null', $probe, $probeStatus);
    if ($probeStatus !== 0 || !in_array('NHK_WP_OK', $probe, true)) throw new RuntimeException('WORDPRESS_DATABASE_UNAVAILABLE');
    require_once $wpLoad;
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb)) throw new RuntimeException('WORDPRESS_DATABASE_UNAVAILABLE');
    $reader = new class($wpdb) implements \NHK\Core\Contracts\Graph\GraphDistributionReader {
        public function __construct(private object $database) {}
        public function rows(): array
        {
            $prefix = $this->database->prefix;
            $rows = $this->database->get_results("SELECT sn.endpoint_type source_type,p.predicate_key predicate,tn.endpoint_type target_type,COUNT(*) edge_count FROM {$prefix}nhk_graph_edges e JOIN {$prefix}nhk_graph_nodes sn ON sn.id=e.source_node_id JOIN {$prefix}nhk_graph_predicates p ON p.id=e.predicate_id JOIN {$prefix}nhk_graph_nodes tn ON tn.id=e.target_node_id WHERE e.state=1 GROUP BY sn.endpoint_type,p.predicate_key,tn.endpoint_type ORDER BY sn.endpoint_type,p.predicate_key,tn.endpoint_type", ARRAY_A);
            if (!is_array($rows)) throw new RuntimeException('GRAPH_DISTRIBUTION_QUERY_FAILED');
            return array_map(static fn (array $row): array => ['source_type' => (string) $row['source_type'], 'predicate' => (string) $row['predicate'], 'target_type' => (string) $row['target_type'], 'edge_count' => (int) $row['edge_count']], $rows);
        }
        public function diagnostics(\NHK\Core\Domain\Graph\PredicateRegistry $registry): array
        {
            $prefix = $this->database->prefix;
            $dangling = (int) $this->database->get_var("SELECT COUNT(*) FROM {$prefix}nhk_graph_edges e LEFT JOIN {$prefix}nhk_graph_nodes sn ON sn.id=e.source_node_id LEFT JOIN {$prefix}nhk_graph_nodes tn ON tn.id=e.target_node_id WHERE e.state=1 AND (sn.id IS NULL OR tn.id IS NULL)");
            $duplicateRows = $this->database->get_results("SELECT source_node_id,predicate_id,target_node_id,COUNT(*) duplicate_count FROM {$prefix}nhk_graph_edges WHERE state=1 GROUP BY source_node_id,predicate_id,target_node_id HAVING COUNT(*)>1", ARRAY_A);
            $predicateRows = $this->database->get_results("SELECT DISTINCT predicate_key FROM {$prefix}nhk_graph_predicates ORDER BY predicate_key", ARRAY_A);
            $known = array_fill_keys(array_map(static fn ($definition): string => $definition->key, $registry->all()), true);
            $unknown = [];
            foreach (is_array($predicateRows) ? $predicateRows : [] as $row) if (!isset($known[(string) ($row['predicate_key'] ?? '')])) $unknown[] = (string) $row['predicate_key'];
            $violations = 0;
            foreach ($this->rows() as $row) {
                try { $definition = $registry->get($row['predicate']); if (!$definition->allows($row['source_type'], $row['target_type'])) $violations += $row['edge_count']; }
                catch (\Throwable) { $violations += $row['edge_count']; }
            }
            return ['dangling_endpoints' => $dangling, 'unknown_predicates' => $unknown, 'duplicate_edge_candidates' => is_array($duplicateRows) ? array_map(static fn (array $row): array => ['source_node_id' => (int) $row['source_node_id'], 'predicate_id' => (int) $row['predicate_id'], 'target_node_id' => (int) $row['target_node_id'], 'duplicate_count' => (int) $row['duplicate_count']], $duplicateRows) : [], 'endpoint_type_violations' => $violations];
        }
    };
    $registry = new \NHK\Core\Domain\Graph\PredicateRegistry();
    $audit = (new \NHK\Core\Application\Graph\GraphDistributionAudit($reader, $registry))->read();
    $audit['diagnostics'] = $reader->diagnostics($registry);
    if ($format === 'json') { echo json_encode($audit, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL; exit(0); }
    foreach ($audit['distribution'] as $row) echo implode("\t", [$row['source_type'], $row['predicate'], $row['target_type'], $row['edge_count']]) . PHP_EOL;
    echo 'active_edge_total=' . $audit['active_edge_total'] . PHP_EOL;
    echo 'registered_predicate_count=' . $audit['registered_predicate_count'] . PHP_EOL;
    echo json_encode($audit['diagnostics'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'GRAPH_AUDIT_FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
