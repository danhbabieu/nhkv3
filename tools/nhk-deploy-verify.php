<?php
declare(strict_types=1);

use NHK\Core\Application\Demo\DemoCutoverContext;
use NHK\Core\Application\Mcp\McpDocumentationRegistry;
use NHK\Core\Infrastructure\Demo\RemoteDeploymentAdapter;
use NHK\Core\Infrastructure\Demo\RemoteMcpDocumentationVerifier;
use NHK\Core\Infrastructure\Demo\PluginHeaderVersionReader;

$root = dirname(__DIR__);
$target = null;
$baseUrl = null;
$expectedHead = null;
$pull = false;
$json = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        echo "Usage: nhk-deploy-verify --target=demo.1945.vn [--base-url=https://demo.1945.vn] [--expected-head=<40-hex>] [--pull] [--json]\n";
        exit(0);
    }
    if ($argument === '--pull') { $pull = true; continue; }
    if ($argument === '--json') { $json = true; continue; }
    if (str_starts_with($argument, '--target=')) { $target = substr($argument, 9); continue; }
    if (str_starts_with($argument, '--base-url=')) { $baseUrl = substr($argument, 11); continue; }
    if (str_starts_with($argument, '--expected-head=')) { $expectedHead = strtolower(substr($argument, 16)); continue; }
    fwrite(STDERR, "UNKNOWN_ARGUMENT\n");
    exit(64);
}

if ($target === null) finish(['status' => 'blocked', 'reason_code' => 'TARGET_REQUIRED'], $json, 64);
if ($target !== 'demo.1945.vn') finish(['status' => 'blocked', 'reason_code' => 'DEPLOYMENT_TARGET_NOT_ALLOWLISTED'], $json, 2);
$baseUrl ??= 'https://' . $target;
if (!validBaseUrl($baseUrl, $target)) finish(['status' => 'blocked', 'reason_code' => 'MCP_TARGET_NOT_ALLOWLISTED'], $json, 2);
if ($expectedHead !== null && preg_match('/^[a-f0-9]{40}$/', $expectedHead) !== 1) finish(['status' => 'blocked', 'reason_code' => 'EXPECTED_HEAD_INVALID'], $json, 64);

$status = runProcess(['git', '-C', $root, 'status', '--porcelain', '--untracked-files=all'], $root);
if ($status['status'] !== 0) finish(['status' => 'failed', 'reason_code' => 'GIT_STATUS_UNAVAILABLE'], $json, 2);
if (trim($status['stdout']) !== '') finish(['status' => 'blocked', 'reason_code' => 'WORKTREE_NOT_CLEAN'], $json, 2);

if ($pull) {
    $pulled = runProcess(['git', '-C', $root, 'pull', '--ff-only', 'origin', 'main'], $root);
    if ($pulled['status'] !== 0) finish(['status' => 'failed', 'reason_code' => 'GIT_PULL_FAILED'], $json, 2);
}

$headResult = runProcess(['git', '-C', $root, 'rev-parse', '--verify', 'HEAD'], $root);
$head = trim($headResult['stdout']);
if ($headResult['status'] !== 0 || preg_match('/^[a-f0-9]{40}$/', $head) !== 1) finish(['status' => 'failed', 'reason_code' => 'GIT_HEAD_UNAVAILABLE'], $json, 2);
if ($expectedHead !== null && !hash_equals($expectedHead, $head)) finish(['status' => 'blocked', 'reason_code' => 'GIT_HEAD_MISMATCH'], $json, 2);

$composer = runProcess(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress'], $root);
if ($composer['status'] !== 0) finish(['status' => 'failed', 'reason_code' => 'COMPOSER_INSTALL_FAILED'], $json, 2);

$generated = runProcess(['composer', 'generate:mcp-docs'], $root);
if ($generated['status'] !== 0) finish(['status' => 'failed', 'reason_code' => 'DOC_BUILD_FAILED'], $json, 2);

$autoload = $root . '/vendor/autoload.php';
if (!is_readable($autoload)) finish(['status' => 'failed', 'reason_code' => 'COMPOSER_AUTOLOAD_MISSING'], $json, 2);
require_once $autoload;

$pluginRoot = $root . '/public/wp-content/plugins/nhk-core';
    $runtimeVersion = PluginHeaderVersionReader::read($pluginRoot . '/nhk-core.php');
try {
    if ($runtimeVersion === null) throw new RuntimeException('PLUGIN_VERSION_UNAVAILABLE');
    $localBootstrap = (new McpDocumentationRegistry($pluginRoot . '/resources/canonical-docs', $runtimeVersion))->bootstrap();
    $expectedManifest = is_array($localBootstrap['manifest'] ?? null) ? $localBootstrap['manifest'] : [];
    $expectedBuildIdentity = (string) ($localBootstrap['build_identity'] ?? '');
    if ($expectedManifest === [] || !preg_match('/^[a-f0-9]{64}$/', $expectedBuildIdentity)) throw new RuntimeException('DOC_MANIFEST_INVALID');
} catch (Throwable) {
    finish(['status' => 'failed', 'reason_code' => 'DOC_BUILD_FAILED'], $json, 2);
}

$deployment = RemoteDeploymentAdapter::fromEnvironment($root)->deploy(new DemoCutoverContext($target, 'deployment', $head, bin2hex(random_bytes(8))));
if (!$deployment->isPass()) finish(['status' => $deployment->status, 'reason_code' => $deployment->reasonCode], $json, 2);
// The transport fingerprint covers the deployed plugin tree; the MCP build
// identity covers the canonical documentation projection. They are distinct
// identities and are verified independently below.
if ((string) $deployment->fingerprint === '') finish(['status' => 'failed', 'reason_code' => 'DEPLOYMENT_IDENTITY_UNAVAILABLE'], $json, 2);

$verifier = new RemoteMcpDocumentationVerifier(static function (string $url, string $method, array $headers, string $body): array {
    if (!function_exists('curl_init')) return ['status' => 0, 'body' => ''];
    $handle = curl_init($url);
    if ($handle === false) return ['status' => 0, 'body' => ''];
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return ['status' => $status, 'body' => is_string($response) ? $response : ''];
});
$verification = $verifier->verify($baseUrl, $expectedManifest, $expectedBuildIdentity);
if (!$verification->isPass()) {
    finish([
        'status' => $verification->status,
        'reason_code' => $verification->reasonCode,
        'expected' => safeIdentity($expectedManifest, $expectedBuildIdentity),
    ], $json, 2);
}

finish([
    'status' => 'pass',
    'source_revision' => $head,
    'documentation_version' => $expectedManifest['documentation_version'],
    'manifest_hash' => $expectedManifest['manifest_hash'],
    'build_identity' => $expectedBuildIdentity,
    'documents' => count((array) ($expectedManifest['files'] ?? [])),
    'deployment_identifier' => $deployment->identifier,
    'verification' => 'direct-mcp-bootstrap-and-list',
], $json, 0);

/** @param list<string> $command @return array{status:int,stdout:string,stderr:string} */
function runProcess(array $command, string $cwd): array
{
    $process = proc_open(implode(' ', array_map('escapeshellarg', $command)), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) return ['status' => 127, 'stdout' => '', 'stderr' => ''];
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['status' => proc_close($process), 'stdout' => is_string($stdout) ? $stdout : '', 'stderr' => is_string($stderr) ? $stderr : ''];
}

function validBaseUrl(string $baseUrl, string $target): bool
{
    $parts = parse_url(rtrim(trim($baseUrl), '/'));
    return is_array($parts)
        && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        && strtolower((string) ($parts['host'] ?? '')) === $target
        && !isset($parts['user'], $parts['pass']);
}

/** @param array<string,mixed> $manifest @return array<string,mixed> */
function safeIdentity(array $manifest, string $buildIdentity): array
{
    return ['documentation_version' => $manifest['documentation_version'] ?? null, 'manifest_hash' => $manifest['manifest_hash'] ?? null, 'build_identity' => $buildIdentity];
}

/** @param array<string,mixed> $payload */
function finish(array $payload, bool $json, int $status): never
{
    if ($json) echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    else echo strtoupper((string) ($payload['status'] ?? 'failed')) . ': ' . (string) ($payload['reason_code'] ?? 'OK') . PHP_EOL;
    exit($status);
}
