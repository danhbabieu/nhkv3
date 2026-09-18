<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$runtimeVersion = getenv('NHK_V3_RUNTIME_VERSION');
$runtimeVersion = is_string($runtimeVersion) && trim($runtimeVersion) !== '' ? trim($runtimeVersion) : '0.1.0';
$destination = $root . '/public/wp-content/plugins/nhk-core/resources/canonical-docs';
try {
    $sourceRevision = \NHK\Core\Application\Mcp\McpDocumentationRegistry::sourceRevision($root);
    $manifest = \NHK\Core\Application\Mcp\McpDocumentationRegistry::buildSnapshot($root, $destination, $runtimeVersion, null, $sourceRevision);
} catch (\Throwable $error) {
    fwrite(STDERR, 'DOC_BUILD_FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}

fwrite(STDOUT, 'Generated ' . count((array) ($manifest['files'] ?? [])) . ' canonical MCP documentation files. source_revision=' . $manifest['source_revision'] . ' manifest_hash=' . $manifest['manifest_hash'] . PHP_EOL);
