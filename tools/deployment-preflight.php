<?php
declare(strict_types=1);

// Read-only release gate. It reports failures instead of repairing code,
// installing dependencies, or changing database state.
$root = getcwd();
$expectedHead = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--root=')) $root = rtrim(substr($argument, 7), '/');
    if (str_starts_with($argument, '--expected-head=')) $expectedHead = strtolower(substr($argument, 16));
}

$checks = [];
$check = static function (string $name, bool $ok, string $reason) use (&$checks): void {
    $checks[] = ['check' => $name, 'status' => $ok ? 'PASS' : 'FAIL', 'reason_code' => $ok ? null : $reason];
};

$head = trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null'));
$check('git_head', preg_match('/^[0-9a-f]{40}$/', $head) === 1 && ($expectedHead === null || hash_equals($expectedHead, $head)), $expectedHead === null ? 'GIT_HEAD_UNAVAILABLE' : 'GIT_HEAD_MISMATCH');
$check('composer_lock', is_file($root . '/composer.lock'), 'COMPOSER_LOCK_MISSING');
$autoload = $root . '/vendor/autoload.php';
$check('root_composer_autoload', is_readable($autoload) && !is_file($root . '/public/wp-content/plugins/nhk-core/vendor/autoload.php'), 'ROOT_COMPOSER_RUNTIME_INVALID');

$autoloadLoaded = false;
if (is_readable($autoload)) { require_once $autoload; $autoloadLoaded = true; }
$check('symfony_uid', $autoloadLoaded && class_exists('Symfony\\Component\\Uid\\Uuid'), 'SYMFONY_UID_UNAVAILABLE');
$check('nhk_runtime_classes', $autoloadLoaded && class_exists('NHK\\Core\\Plugin') && class_exists('NHK\\Core\\Infrastructure\\Authority\\WpdbAuthorityRepository'), 'NHK_RUNTIME_CLASS_UNAVAILABLE');

$wpLoaded = false;
$wpLoad = $root . '/public/wp-load.php';
if (is_readable($wpLoad)) {
    $probeOutput = [];
    $probeStatus = 1;
    $probeCode = 'require ' . var_export($wpLoad, true) . '; echo "NHK_WP_OK";';
    exec(PHP_BINARY . ' -r ' . escapeshellarg($probeCode) . ' 2>&1', $probeOutput, $probeStatus);
    $wpLoaded = $probeStatus === 0 && in_array('NHK_WP_OK', $probeOutput, true);
    if ($wpLoaded) {
        try { require_once $wpLoad; $wpLoaded = defined('ABSPATH') && function_exists('get_option'); } catch (Throwable) { $wpLoaded = false; }
    }
}
$check('wordpress_bootstrap', $wpLoaded, 'WORDPRESS_BOOTSTRAP_FAILED');

$pluginLoaded = false;
if ($wpLoaded && is_readable($root . '/public/wp-content/plugins/nhk-core/nhk-core.php')) {
    try { require_once $root . '/public/wp-content/plugins/nhk-core/nhk-core.php'; $pluginLoaded = class_exists('NHK\\Core\\Plugin'); } catch (Throwable) { $pluginLoaded = false; }
}
$check('nhk_core_bootstrap', $pluginLoaded, 'NHK_CORE_BOOTSTRAP_FAILED');

if ($wpLoaded) {
    global $wpdb;
    $migration = new NHK\Core\Shared\Migration\MigrationStatus();
    $state = $migration->status();
    $check('schema_migration', $state['current'] >= $state['target'], 'MIGRATION_REQUIRED');
    $hydrationOk = false;
    if (isset($wpdb) && is_object($wpdb)) {
        $types = new NHK\Core\Domain\Authority\EntityTypeRegistry();
        NHK\Core\Domain\Authority\CanonicalEntityTypeCatalog::registerInto($types);
        try { $repository = new NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository($wpdb); foreach ($types->all() as $definition) $repository->listByType($definition->type); $hydrationOk = true; } catch (Throwable) { $hydrationOk = false; }
    }
    $check('authority_hydration_capability', $hydrationOk, 'AUTHORITY_HYDRATION_FAILED');
    $restOk = false;
    if (function_exists('rest_get_server')) { do_action('rest_api_init'); $restOk = rest_get_server() !== null; }
    $check('rest_bootstrap', $restOk, 'REST_BOOTSTRAP_FAILED');
} else {
    $check('schema_migration', false, 'WORDPRESS_BOOTSTRAP_FAILED');
    $check('authority_hydration_capability', false, 'WORDPRESS_BOOTSTRAP_FAILED');
    $check('rest_bootstrap', false, 'WORDPRESS_BOOTSTRAP_FAILED');
}

foreach ($checks as $item) echo json_encode($item, JSON_UNESCAPED_SLASHES) . PHP_EOL;
$failed = count(array_filter($checks, static fn (array $item): bool => $item['status'] === 'FAIL'));
echo json_encode(['summary' => ['checks' => count($checks), 'failed' => $failed]], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
