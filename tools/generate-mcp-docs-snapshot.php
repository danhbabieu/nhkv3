<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$runtimeVersion = getenv('NHK_V3_RUNTIME_VERSION');
$runtimeVersion = is_string($runtimeVersion) && trim($runtimeVersion) !== '' ? trim($runtimeVersion) : '0.1.0';
$destination = $root . '/public/wp-content/plugins/nhk-core/resources/canonical-docs';
$manifest = \NHK\Core\Application\Mcp\McpDocumentationRegistry::buildSnapshot($root, $destination, $runtimeVersion);

fwrite(STDOUT, 'Generated ' . count((array) ($manifest['files'] ?? [])) . ' canonical MCP documentation files. ' . $manifest['manifest_hash'] . PHP_EOL);
