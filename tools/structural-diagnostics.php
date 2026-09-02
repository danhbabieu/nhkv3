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

    $types = new \NHK\Core\Domain\Authority\EntityTypeRegistry();
    \NHK\Core\Domain\Authority\CanonicalEntityTypeCatalog::registerInto($types);
    $authority = new \NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository($wpdb);
    $media = new \NHK\Core\Infrastructure\Media\WpdbMediaRepository($wpdb);
    $videos = new \NHK\Core\Infrastructure\Video\WpdbVideoRepository($wpdb);
    $endpoints = new \NHK\Core\Domain\Graph\EndpointTypeRegistry();
    \NHK\Core\Infrastructure\Graph\CoreEndpointResolverRegistrar::register($endpoints, $types, $authority, $media, $videos);
    $graph = new \NHK\Core\Application\Graph\GraphService(new \NHK\Core\Infrastructure\Graph\WpdbGraphRepository($wpdb), $endpoints, new \NHK\Core\Domain\Graph\PredicateRegistry(), new \NHK\Core\Infrastructure\Graph\WpdbAuditSink());
    $diagnostics = (new \NHK\Core\Application\Graph\StructuralDiagnostics($authority, new \NHK\Core\Application\Graph\StructuralContextQuery($graph, $authority)))->read();

    if ($format === 'json') { echo json_encode(['findings' => $diagnostics, 'count' => count($diagnostics)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL; exit(0); }
    foreach ($diagnostics as $finding) echo implode("\t", [$finding['entity_type'], $finding['entity_id'], $finding['status'], $finding['reason_code'], implode(',', $finding['parent_candidates'])]) . PHP_EOL;
    echo 'finding_count=' . count($diagnostics) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'STRUCTURAL_DIAGNOSTICS_FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
