<?php
declare(strict_types=1);

use NHK\Core\Application\Migration\V2DomainPostClassifier;

require dirname(__DIR__) . '/vendor/autoload.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "Usage: php tools/v2-domain-post-classify.php /path/to/export.json\n");
    exit(2);
}

$decoded = json_decode((string) file_get_contents($path), true);
if (!is_array($decoded) || !is_array($decoded['records'] ?? null)) {
    fwrite(STDERR, "Input must be a JSON object containing a records array.\n");
    exit(2);
}

$domainPosts = array_values(array_filter($decoded['records'], static function (mixed $record): bool {
    if (!is_array($record) || (string) ($record['type'] ?? '') !== 'wp_post') return false;
    return !in_array((string) ($record['legacy_type'] ?? ''), ['nhk_article', 'post', 'page'], true);
}));
$report = (new V2DomainPostClassifier())->run($domainPosts);
$report['inventory_source_count'] = count($decoded['records']);
echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
