<?php
declare(strict_types=1);
if (in_array('--help', $argv ?? [], true)) { echo "Usage: public-identity-readiness-audit [--help]\nRead-only audit; no identity, slug, route or database writes.\n"; exit(0); }
require dirname(__DIR__) . '/public/wp-load.php';
echo wp_json_encode(['status' => 'ENVIRONMENT_BLOCKED', 'mutation_count' => 0, 'reason' => 'No authorized public-identity audit adapter is configured.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
