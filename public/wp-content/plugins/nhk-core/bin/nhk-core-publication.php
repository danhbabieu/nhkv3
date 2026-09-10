<?php
declare(strict_types=1);

use NHK\Core\Application\Article\ArticlePublicationContinuationCommand;
use NHK\Core\Application\Mcp\McpDocumentationRegistry;
use NHK\Core\Infrastructure\Article\WpEditorialStateReader;
use NHK\Core\Infrastructure\Capture\WpdbCaptureRepository;

/**
 * Canonical CLI orchestration for an existing Capture-owned Article.
 *
 * The command deliberately invokes the MCP Ability surface. It does not
 * contain a WordPress writer or a second publication implementation.
 */
$values = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z0-9_-]+)=(.*)$/s', $argument, $match) === 1) { $values[$match[1]] = $match[2]; continue; }
    fwrite(STDERR, "UNKNOWN_ARGUMENT\n"); exit(64);
}
$operation = trim((string) ($values['operation'] ?? ''));
$captureId = trim((string) ($values['capture-id'] ?? ''));
$articleId = (int) ($values['article-id'] ?? 0);
$idempotencyKey = trim((string) ($values['idempotency-key'] ?? ''));
$evidenceFile = trim((string) ($values['evidence-file'] ?? ''));
if (!in_array($operation, ['run', 'review', 'approve', 'publish'], true) || $captureId === '' || $articleId < 1 || $idempotencyKey === '' || $evidenceFile === '') {
    echo json_encode(['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['CLI_ARGUMENTS_REQUIRED']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL; exit(2);
}
if (!is_file($evidenceFile) || !is_readable($evidenceFile)) {
    echo json_encode(['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['PUBLICATION_EVIDENCE_FILE_UNAVAILABLE']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL; exit(2);
}
$evidence = json_decode((string) file_get_contents($evidenceFile), true);
if (!is_array($evidence)) {
    echo json_encode(['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['PUBLICATION_EVIDENCE_INVALID']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL; exit(2);
}
$wordpressRoot = dirname(__DIR__, 4);
$wpLoad = $wordpressRoot . '/wp-load.php';
if (!is_readable($wpLoad)) {
    echo json_encode(['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['WORDPRESS_BOOTSTRAP_UNAVAILABLE']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL; exit(2);
}
try {
    require_once $wpLoad;
    if (isset($values['user-id']) && function_exists('wp_set_current_user')) wp_set_current_user((int) $values['user-id']);
    global $wpdb;
    $documentation = new McpDocumentationRegistry();
    $bootstrap = $documentation->bootstrap();
    $invoke = static function (string $tool, array $arguments): array {
        do_action('rest_api_init');
        $request = new WP_REST_Request('POST', '/nhk/v1/mcp');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        $request->set_header('MCP-Protocol-Version', \NHK\Core\Application\Mcp\McpTransport::MODERN_VERSION);
        $request->set_header('Mcp-Method', 'tools/call');
        $request->set_header('Mcp-Name', $tool);
        $request->set_body((string) wp_json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments, 'protocolVersion' => \NHK\Core\Application\Mcp\McpTransport::MODERN_VERSION]]));
        $response = rest_do_request($request);
        if (is_wp_error($response)) throw new RuntimeException('CANONICAL_MCP_REQUEST_FAILED');
        $body = $response->get_data();
        $result = is_array($body) ? ($body['result'] ?? null) : null;
        if (!is_array($result) || ($result['isError'] ?? false) === true) throw new RuntimeException('CANONICAL_MCP_ABILITY_FAILED');
        $structured = $result['structuredContent'] ?? null;
        if (!is_array($structured)) throw new RuntimeException('CANONICAL_MCP_RECEIPT_INVALID');
        return $structured;
    };
    $publicReadBack = static function (string $url): array {
        $response = wp_remote_get($url, ['timeout' => 20, 'redirection' => 3]);
        if (is_wp_error($response)) return ['status' => 'unavailable', 'error' => 'PUBLIC_ROUTE_REQUEST_FAILED'];
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        return $status >= 200 && $status < 300 && trim($body) !== '' ? ['status' => 'verified', 'http_status' => $status, 'url' => $url] : ['status' => 'failed', 'http_status' => $status, 'url' => $url];
    };
    $command = new ArticlePublicationContinuationCommand(new WpdbCaptureRepository($wpdb), new WpEditorialStateReader(), $invoke, static fn (): array => ['documentation_version' => (string) $bootstrap['documentation_version'], 'manifest_hash' => (string) $bootstrap['manifest_hash']], $publicReadBack);
    $input = ['operation' => $operation, 'capture_id' => $captureId, 'article_id' => $articleId, 'idempotency_key' => $idempotencyKey, 'evidence' => $evidence];
    foreach (['decision-id' => 'decision_id', 'affirmation' => 'affirmation', 'expected-state-token' => 'expected_state_token'] as $option => $key) if (array_key_exists($option, $values)) $input[$key] = $values[$option];
    $result = $command->execute($input);
} catch (Throwable $error) {
    $result = ['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['CLI_RUNTIME_FAILED'], 'error_class' => get_class($error)];
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit(($result['outcome'] ?? '') === 'PASS' ? 0 : 2);
