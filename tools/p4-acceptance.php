<?php
declare(strict_types=1);

if (getenv('NHK_WP_TEST_PATH') !== 'public') {
    fwrite(STDERR, "P4 acceptance requires NHK_WP_TEST_PATH=public; refusing to run without the configured test runtime.\n");
    exit(2);
}
$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
$wpLoad = $root . '/public/wp-load.php';
if (!is_readable($wpLoad)) { fwrite(STDERR, "P4 acceptance database preflight failed: WordPress bootstrap unavailable.\n"); exit(3); }
require_once $wpLoad;
global $wpdb;
$identity = [
    'environment' => defined('WP_ENVIRONMENT_TYPE') ? (string) constant('WP_ENVIRONMENT_TYPE') : '',
    'database' => isset($wpdb) && is_object($wpdb) ? (string) $wpdb->get_var('SELECT DATABASE()') : '',
    'site_url' => function_exists('home_url') ? (string) home_url('/') : '',
    'project' => class_exists('NHK\\Core\\Plugin') ? 'nhk-v3' : '',
    'runtime_identity' => class_exists('NHK\\Core\\Plugin') ? 'nhk-v3' : '',
];
$decision = \NHK\Core\Shared\TestRuntimeIdentityPolicy::evaluate($identity);
if (($decision['allowed'] ?? false) !== true) {
    fwrite(STDERR, "P4 acceptance runtime identity rejected: " . (string) ($decision['reason'] ?? 'IDENTITY_REJECTED') . "\n");
    exit(3);
}
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../vendor/bin/phpunit').' --configuration '.escapeshellarg(__DIR__.'/../phpunit.xml.dist').' --testsuite '.escapeshellarg('NHK Integration');
passthru($command, $status);
exit($status);
