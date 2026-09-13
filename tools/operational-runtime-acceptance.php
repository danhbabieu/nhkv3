<?php
declare(strict_types=1);

use NHK\Core\Application\Mcp\McpDocumentationRegistry;
use NHK\Core\Application\Mcp\McpToolCatalog;
use NHK\Core\Infrastructure\PublicIdentity\WpdbPublicIdentityRepository;
use NHK\Core\Shared\Migration\MigrationStatus;

/**
 * Read-only operational runtime acceptance.
 *
 * This command verifies a runtime already provisioned by infrastructure. It
 * does not provision infrastructure, call an MCP tool, or write WordPress or
 * semantic state.
 */

$options = parseOptions(array_slice($argv, 1));
if (isset($options['help'])) {
    echo "Usage: php tools/operational-runtime-acceptance.php --expected-environment=<name> --expected-site=<url> --expected-database-binding-id=<opaque-id> --previous-database-binding-id=<opaque-id> --expected-connector-id=<id> --connector-proof=verified --write-policy=governed [--root=<path>] [--json]\n";
    exit(0);
}

$root = rtrim((string) ($options['root'] ?? dirname(__DIR__)), DIRECTORY_SEPARATOR);
$expectedEnvironment = trim((string) ($options['expected-environment'] ?? ''));
$expectedSite = normalizeSite((string) ($options['expected-site'] ?? ''));
$expectedBinding = trim((string) ($options['expected-database-binding-id'] ?? ''));
$previousBinding = trim((string) ($options['previous-database-binding-id'] ?? ''));
$connectorId = trim((string) ($options['expected-connector-id'] ?? ''));
$connectorProof = trim((string) ($options['connector-proof'] ?? ''));
$writePolicy = trim((string) ($options['write-policy'] ?? ''));

if ($expectedEnvironment === '' || $expectedSite === '' || $expectedBinding === '' || $previousBinding === '') {
    finish(['status' => 'blocked', 'reason_code' => 'OPERATIONAL_TARGET_REQUIRED'], $options);
}
if ($connectorId === '') finish(['status' => 'blocked', 'reason_code' => 'CONNECTOR_ID_REQUIRED'], $options);
if ($connectorProof !== 'verified') finish(['status' => 'blocked', 'reason_code' => 'CONNECTOR_PROOF_REQUIRED'], $options);
if ($writePolicy !== 'governed') finish(['status' => 'blocked', 'reason_code' => 'WRITE_POLICY_UNVERIFIED'], $options);
if (forbiddenTarget($expectedEnvironment, $expectedSite)) {
    finish(['status' => 'blocked', 'reason_code' => 'OPERATIONAL_TARGET_FORBIDDEN'], $options);
}

$autoload = $root . '/vendor/autoload.php';
if (!is_readable($autoload)) finish(['status' => 'blocked', 'reason_code' => 'RUNTIME_AUTOLOAD_UNAVAILABLE'], $options);
require_once $autoload;

$wpLoad = $root . '/public/wp-load.php';
if (!is_readable($wpLoad)) finish(['status' => 'blocked', 'reason_code' => 'WORDPRESS_BOOTSTRAP_UNAVAILABLE'], $options);
try {
    require_once $wpLoad;
} catch (Throwable) {
    finish(['status' => 'blocked', 'reason_code' => 'WORDPRESS_BOOTSTRAP_UNAVAILABLE'], $options);
}

if (!class_exists('NHK\\Core\\Plugin')) finish(['status' => 'blocked', 'reason_code' => 'NHK_CORE_NOT_ACTIVE'], $options);
if (!function_exists('home_url') || !function_exists('rest_get_server')) finish(['status' => 'blocked', 'reason_code' => 'READ_SURFACE_UNAVAILABLE'], $options);

$actualEnvironment = runtimeValue('NHK_RUNTIME_ENVIRONMENT', 'WP_ENVIRONMENT_TYPE');
$actualMode = runtimeValue('NHK_RUNTIME_MODE', 'WP_ENVIRONMENT_TYPE');
$actualSite = normalizeSite((string) home_url('/'));
if ($actualEnvironment !== $expectedEnvironment || $actualSite !== $expectedSite || forbiddenTarget($actualEnvironment, $actualSite) || strtolower($actualMode) === 'recovery') {
    finish(['status' => 'blocked', 'reason_code' => 'OPERATIONAL_TARGET_FORBIDDEN', 'identity' => ['environment' => $actualEnvironment, 'site_url' => $actualSite]], $options);
}

global $wpdb;
if (!isset($wpdb) || !is_object($wpdb) || !defined('DB_NAME')) finish(['status' => 'blocked', 'reason_code' => 'DATABASE_BINDING_UNAVAILABLE'], $options);
$database = trim((string) $wpdb->get_var('SELECT DATABASE()'));
$prefix = (string) ($wpdb->prefix ?? '');
if ($database === '' || $prefix === '') finish(['status' => 'blocked', 'reason_code' => 'DATABASE_BINDING_UNAVAILABLE'], $options);
if ($database === 'nhk_v3_test') finish(['status' => 'blocked', 'reason_code' => 'OPERATIONAL_TARGET_FORBIDDEN'], $options);
$binding = hash('sha256', implode("\0", [$actualEnvironment, $actualSite, $database, $prefix]));
if (!hash_equals($expectedBinding, $binding) || !hash_equals($previousBinding, $binding)) {
    finish(['status' => 'blocked', 'reason_code' => 'DATABASE_BINDING_MISMATCH', 'identity' => ['environment' => $actualEnvironment, 'site_url' => $actualSite, 'database_binding_id' => $binding]], $options);
}

$runtimeVersion = defined('NHK_CORE_VERSION') ? (string) NHK_CORE_VERSION : '';
$documentation = null;
try {
    $documentation = (new McpDocumentationRegistry($root . '/public/wp-content/plugins/nhk-core/resources/canonical-docs', $runtimeVersion))->bootstrap();
} catch (Throwable) {
    finish(['status' => 'blocked', 'reason_code' => 'CANONICAL_DOCUMENTATION_UNAVAILABLE'], $options);
}
if (!is_array($documentation) || !preg_match('/^[a-f0-9]{64}$/i', (string) ($documentation['documentation_version'] ?? '')) || !preg_match('/^[a-f0-9]{64}$/i', (string) ($documentation['manifest_hash'] ?? ''))) {
    finish(['status' => 'blocked', 'reason_code' => 'CANONICAL_DOCUMENTATION_UNAVAILABLE'], $options);
}

$migration = new MigrationStatus();
$migrationState = $migration->status();
$storage = [
    'authority' => safeCheck(static fn (): bool => $migration->authorityStorageReady()),
    'graph' => safeCheck(static fn (): bool => $migration->graphStorageReady()),
    'governance' => safeCheck(static fn (): bool => $migration->governanceStorageReady()),
    'knowledge' => safeCheck(static fn (): bool => $migration->knowledgeStorageReady()),
    'media' => safeCheck(static fn (): bool => $migration->mediaStorageReady()),
    'video' => safeCheck(static fn (): bool => $migration->videoStorageReady()),
    'schema' => safeCheck(static fn (): bool => $migration->runtimeSchemaReady()),
    'public_identity' => class_exists(WpdbPublicIdentityRepository::class) && tableExists($wpdb, $prefix . 'nhk_public_identities'),
];
if ((int) ($migrationState['current'] ?? 0) < (int) ($migrationState['target'] ?? 0) || !$storage['schema']) {
    finish(['status' => 'blocked', 'reason_code' => 'MIGRATION_REQUIRED', 'migrations' => $migrationState, 'storage' => $storage], $options);
}

do_action('rest_api_init');
$routes = rest_get_server()->get_routes();
$routeChecks = [];
foreach ([
    'authority' => '/nhk/v1/entity',
    'graph' => '/nhk/v1/graph/',
    'knowledge' => '/nhk/v1/knowledge/',
    'media' => '/nhk/v1/media/',
    'video' => '/nhk/v1/video/',
    'mcp' => '/nhk/v1/mcp',
] as $name => $needle) {
    $routeChecks[$name] = array_reduce(array_keys($routes), static fn (bool $found, string $route): bool => $found || str_starts_with($route, $needle), false);
}
$readSurfaces = [
    'authority' => $storage['authority'] && $routeChecks['authority'],
    'graph' => $storage['graph'] && $routeChecks['graph'],
    'knowledge' => $storage['knowledge'] && $routeChecks['knowledge'],
    'media' => $storage['media'] && $routeChecks['media'],
    'video' => $storage['video'] && $routeChecks['video'],
    'public_identity' => $storage['public_identity'],
];
if (in_array(false, $readSurfaces, true)) {
    finish(['status' => 'blocked', 'reason_code' => 'READ_SURFACE_UNAVAILABLE', 'read_surfaces' => $readSurfaces, 'routes' => $routeChecks], $options);
}

$catalogNames = array_values(array_filter(array_map(static fn (mixed $tool): string => is_array($tool) ? (string) ($tool['name'] ?? '') : '', McpToolCatalog::tools())));
$requiredTools = [
    'nhk.docs.bootstrap', 'nhk.docs.get', 'nhk.documentation.list', 'nhk.search',
    'nhk.canonical.inventory', 'nhk.entity.get', 'nhk.graph.inventory',
    'nhk.capture.ingest', 'nhk.proposal.review', 'nhk.proposal.approve',
    'nhk.proposal.eligibility', 'nhk.proposal.apply',
];
$missingTools = array_values(array_diff($requiredTools, $catalogNames));
if ($missingTools !== []) finish(['status' => 'blocked', 'reason_code' => 'MCP_CAPABILITY_UNAVAILABLE', 'missing_tools' => $missingTools], $options);

finish([
    'status' => 'pass',
    'reason_code' => null,
    'identity' => [
        'environment' => $actualEnvironment,
        'site_url' => $actualSite,
        'runtime_version' => $runtimeVersion,
        'database_binding_id' => $binding,
        'connector_id' => $connectorId,
        'build_identity' => (string) ($documentation['build_identity'] ?? ''),
        'documentation_version' => (string) $documentation['documentation_version'],
        'manifest_hash' => (string) $documentation['manifest_hash'],
        'write_policy' => $writePolicy,
    ],
    'migrations' => $migrationState,
    'storage' => $storage,
    'read_surfaces' => $readSurfaces,
    'routes' => $routeChecks,
    'mcp' => ['connector_proof' => $connectorProof, 'required_tools' => $requiredTools],
    'persistence' => ['request_boundary_binding_match' => true, 'previous_binding_match' => true],
    'semantic_mutation' => false,
], $options);

/** @return array<string,string|bool> */
function parseOptions(array $arguments): array
{
    $allowed = ['root', 'expected-environment', 'expected-site', 'expected-database-binding-id', 'previous-database-binding-id', 'expected-connector-id', 'connector-proof', 'write-policy'];
    $options = [];
    foreach ($arguments as $argument) {
        if ($argument === '--help') { $options['help'] = true; continue; }
        if ($argument === '--json') { $options['json'] = true; continue; }
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) finish(['status' => 'blocked', 'reason_code' => 'UNKNOWN_ARGUMENT'], $options);
        [$key, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($key, $allowed, true) || trim($value) === '') finish(['status' => 'blocked', 'reason_code' => 'UNKNOWN_ARGUMENT'], $options);
        $options[$key] = $value;
    }
    return $options;
}

function normalizeSite(string $site): string
{
    $site = trim($site);
    if ($site === '' || filter_var($site, FILTER_VALIDATE_URL) === false) return '';
    return rtrim($site, '/');
}

function forbiddenTarget(string $environment, string $site): bool
{
    $environment = strtolower(trim($environment));
    $host = strtolower((string) parse_url($site, PHP_URL_HOST));
    return in_array($environment, ['staging', 'test', 'recovery', 'demo'], true) || in_array($host, ['demo.1945.vn', 'www.demo.1945.vn'], true);
}

function runtimeValue(string $preferred, string $fallback): string
{
    $value = getenv($preferred);
    if (is_string($value) && trim($value) !== '') return trim($value);
    if (defined($preferred)) return trim((string) constant($preferred));
    $value = getenv($fallback);
    if (is_string($value) && trim($value) !== '') return trim($value);
    return defined($fallback) ? trim((string) constant($fallback)) : '';
}

function tableExists(object $database, string $table): bool
{
    return (string) $database->get_var($database->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}

function safeCheck(Closure $check): bool
{
    try { return $check(); } catch (Throwable) { return false; }
}

function finish(array $payload, array $options): never
{
    $json = isset($options['json']);
    if ($json) {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        foreach ($payload as $key => $value) echo $key . ': ' . (is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . PHP_EOL;
    }
    exit(($payload['status'] ?? null) === 'pass' ? 0 : 2);
}
