<?php
declare(strict_types=1);

/**
 * Read-only local Streamable HTTP MCP smoke test.
 *
 * This intentionally exercises protocol negotiation and CORS only; it never
 * calls a governed mutation tool.
 */
$base = 'http://localhost';
foreach (array_slice($argv, 1) as $argument) if (str_starts_with($argument, '--base-url=')) $base = rtrim(substr($argument, 11), '/');
$url = $base . '/wp-json/nhk/v1/mcp';

/** @return array{status:int,headers:string,body:string} */
function request(string $url, string $method, array $headers = [], ?string $body = null): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => $body,
    ]);
    $response = curl_exec($handle);
    if ($response === false) throw new RuntimeException('MCP request failed: ' . curl_error($handle));
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    return ['status' => $status, 'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
}

function pass(string $label): void { echo "PASS {$label}\n"; }
function fail(string $message): never { fwrite(STDERR, "FAIL {$message}\n"); exit(1); }
function expectStatus(array $response, int $expected, string $label): void { if ($response['status'] !== $expected) fail($label . ': expected HTTP ' . $expected . ', got ' . $response['status']); pass($label); }

try {
    $preflight = request($url, 'OPTIONS', [
        'Origin: http://localhost:3000',
        'Access-Control-Request-Method: POST',
        'Access-Control-Request-Headers: Content-Type, MCP-Protocol-Version, Mcp-Method, Mcp-Name',
    ]);
    expectStatus($preflight, 200, 'CORS preflight');
    if (!preg_match('/^Access-Control-Allow-Origin:\s*http:\/\/localhost:3000\s*$/im', $preflight['headers'])) fail('CORS preflight did not echo the requesting origin');
    preg_match('/^Access-Control-Allow-Headers:\s*(.+)$/im', $preflight['headers'], $match);
    $allowed = array_map('strtolower', array_map('trim', explode(',', $match[1] ?? '')));
    foreach (['content-type', 'mcp-protocol-version', 'mcp-method', 'mcp-name'] as $header) if (!in_array($header, $allowed, true)) fail('CORS preflight missing ' . $header);
    pass('CORS protocol headers');

    $common = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2026-07-28'];
    $initialize = request($url, 'POST', $common, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2026-07-28', 'capabilities' => [], 'clientInfo' => ['name' => 'nhk-wire-smoke', 'version' => '1.0']]], JSON_THROW_ON_ERROR));
    expectStatus($initialize, 200, 'initialize');
    $initializeBody = json_decode($initialize['body'], true);
    if (($initializeBody['result']['protocolVersion'] ?? null) !== '2026-07-28') fail('initialize returned an unexpected protocol version');
    pass('initialize protocol version');

    $tools = request($url, 'POST', $common, json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], JSON_THROW_ON_ERROR));
    expectStatus($tools, 200, 'tools/list');
    $toolsBody = json_decode($tools['body'], true);
    $actualToolNames = array_values(array_map(static fn (array $tool): string => (string) ($tool['name'] ?? ''), is_array($toolsBody['result']['tools'] ?? null) ? $toolsBody['result']['tools'] : []));
    $approvedToolNames = ['nhk.search', 'nhk.semantic.resolve', 'nhk.article.preflight', 'nhk.article.ingest', 'nhk.category.resolve', 'nhk.category.create', 'nhk.category.update', 'nhk.category.assign', 'nhk.category.unassign', 'nhk.category.delete', 'nhk.article.draft.create', 'nhk.article.draft.update', 'nhk.article.publish', 'nhk.article.publish.review', 'nhk.article.publish.approve', 'nhk.article.trash', 'nhk.article.restore', 'nhk.entity.get', 'nhk.media.get', 'nhk.media.ingest', 'nhk.media.attachment.get', 'nhk.video.ingest', 'nhk.video.get', 'nhk.knowledge.get', 'nhk.source.get', 'nhk.evidence.get', 'nhk.knowledge.ingest', 'nhk.source.ingest', 'nhk.evidence.ingest', 'nhk.proposal.create', 'nhk.proposal.submit', 'nhk.proposal.review', 'nhk.proposal.approve', 'nhk.proposal.reject', 'nhk.proposal.eligibility', 'nhk.proposal.apply'];
    if ($actualToolNames !== $approvedToolNames) fail('tools/list did not return the exact canonical 36-tool catalog');
    pass('tools/list catalog');

    $resolve = request($url, 'POST', $common, json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'nhk.semantic.resolve', 'arguments' => ['context' => ['brand' => ['name' => 'Odo']]]]], JSON_THROW_ON_ERROR));
    expectStatus($resolve, 200, 'semantic context resolver');
    $resolveBody = json_decode($resolve['body'], true);
    if (!isset($resolveBody['result']['structuredContent']['resolved'], $resolveBody['result']['structuredContent']['missing'], $resolveBody['result']['structuredContent']['ambiguities'])) fail('semantic resolver returned an incomplete context bundle');
    pass('semantic context resolver contract');

    $invalidOrigin = request($url, 'POST', [...$common, 'Origin: https://invalid.example'], json_encode(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list', 'params' => []], JSON_THROW_ON_ERROR));
    expectStatus($invalidOrigin, 403, 'invalid Origin rejection');

    $notification = request($url, 'POST', $common, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => []], JSON_THROW_ON_ERROR));
    expectStatus($notification, 202, 'initialized notification');
    if (trim($notification['body']) !== '') fail('initialized notification returned a response body');
    pass('initialized notification body');
} catch (Throwable $error) {
    fail($error->getMessage());
}
