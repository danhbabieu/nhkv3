<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$registry = new \NHK\Core\Application\Mcp\McpDocumentationRegistry($root);
$destination = $root . '/public/wp-content/plugins/nhk-core/resources';

foreach ($registry::documentKeys() as $key) {
    $document = $registry->get($key);
    $relative = (string) $document['relative_path'];
    if (str_contains($relative, '..') || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $relative) === 1) {
        throw new \RuntimeException('Unsafe generated documentation path.');
    }
    $target = $destination . DIRECTORY_SEPARATOR . $relative;
    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) throw new \RuntimeException('Unable to create documentation snapshot directory.');
    if (file_put_contents($target, (string) $document['content'], LOCK_EX) === false) throw new \RuntimeException('Unable to write documentation snapshot.');
}

fwrite(STDOUT, 'Generated ' . count($registry::documentKeys()) . " canonical MCP documentation files.\n");
