<?php
declare(strict_types=1);
if (in_array('--help', $argv ?? [], true)) { echo "Usage: public-identity-readiness-audit [--help]\nRead-only audit; no identity, slug, route or database writes.\n"; exit(0); }
$path = getenv('NHK_WP_TEST_PATH');
if ($path === false || trim($path) === '') {
    echo json_encode(['status' => 'ENVIRONMENT_BLOCKED', 'mutation_count' => 0, 'reason' => 'NHK_WP_TEST_PATH is unavailable.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
require rtrim($path, '/') . '/wp-load.php';
echo wp_json_encode(['status' => 'ENVIRONMENT_BLOCKED', 'mutation_count' => 0, 'reason' => 'No authorized public-identity audit adapter is configured.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
