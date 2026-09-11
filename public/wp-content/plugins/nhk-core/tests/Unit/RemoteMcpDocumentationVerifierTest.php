<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Demo\RemoteMcpDocumentationVerifier;
use PHPUnit\Framework\TestCase;

final class RemoteMcpDocumentationVerifierTest extends TestCase
{
    public function test_direct_mcp_bootstrap_and_manifest_are_verified(): void
    {
        $expected = $this->expectedBootstrap();
        $calls = [];
        $verifier = new RemoteMcpDocumentationVerifier(function (string $url, string $method, array $headers, string $body) use (&$calls, $expected): array {
            $calls[] = [$url, $method, $headers, $body];
            $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return match ($request['method'] ?? null) {
                'initialize' => ['status' => 200, 'body' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2026-07-28']], JSON_THROW_ON_ERROR)],
                'tools/list' => ['status' => 200, 'body' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => [
                    ['name' => 'nhk.documentation.bootstrap'],
                    ['name' => 'nhk.documentation.get'],
                    ['name' => 'nhk.documentation.list'],
                ]]], JSON_THROW_ON_ERROR)],
                'tools/call' => ($request['params']['name'] ?? '') === 'nhk.documentation.bootstrap'
                    ? $this->jsonResponse($expected)
                    : $this->jsonResponse(['documentation_version' => $expected['documentation_version'], 'manifest_hash' => $expected['manifest_hash'], 'files' => $expected['files']]),
                default => ['status' => 404, 'body' => ''],
            };
        });

        $result = $verifier->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('pass', $result->status, (string) $result->reasonCode);
        self::assertSame('mcp-documentation-verified', $result->identifier);
        self::assertSame(str_repeat('f', 64), $result->fingerprint);
        self::assertCount(4, $calls);
        self::assertSame('https://demo.example/wp-json/nhk/v1/mcp', $calls[0][0]);
        self::assertSame('POST', $calls[0][1]);
        self::assertContains('MCP-Protocol-Version: 2026-07-28', $calls[0][2]);
    }

    public function test_document_hash_mismatch_fails_closed(): void
    {
        $expected = $this->expectedBootstrap();
        $actual = $expected;
        $actual['files'][0]['sha256'] = str_repeat('c', 64);
        $verifier = $this->verifierFor($actual);

        $result = $verifier->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('DOC_MANIFEST_MISMATCH', $result->reasonCode);
    }

    public function test_old_build_identity_is_not_reported_as_active(): void
    {
        $expected = $this->expectedBootstrap();
        $actual = $expected;
        $actual['build_identity'] = str_repeat('0', 64);
        $verifier = $this->verifierFor($actual);

        $result = $verifier->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('DEPLOYMENT_NOT_ACTIVE', $result->reasonCode);
    }

    public function test_old_build_identity_wins_when_the_target_manifest_is_old_too(): void
    {
        $expected = $this->expectedBootstrap();
        $actual = $expected;
        $actual['documentation_version'] = str_repeat('1', 64);
        $actual['manifest_hash'] = str_repeat('2', 64);
        $actual['build_identity'] = str_repeat('0', 64);
        $verifier = $this->verifierFor($actual);

        $result = $verifier->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('DEPLOYMENT_NOT_ACTIVE', $result->reasonCode);
    }

    public function test_unavailable_or_malformed_mcp_fails_closed(): void
    {
        $verifier = new RemoteMcpDocumentationVerifier(static fn (): array => ['status' => 502, 'body' => 'upstream unavailable']);

        $result = $verifier->verify('https://demo.example', $this->expectedBootstrap(), str_repeat('f', 64));

        self::assertSame('MCP_BOOTSTRAP_UNAVAILABLE', $result->reasonCode);
    }

    /** @param array<string,mixed> $payload */
    private function jsonResponse(array $payload): array
    {
        return ['status' => 200, 'body' => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['structuredContent' => $payload, 'isError' => false]], JSON_THROW_ON_ERROR)];
    }

    /** @param array<string,mixed> $actual */
    private function verifierFor(array $actual): RemoteMcpDocumentationVerifier
    {
        return new RemoteMcpDocumentationVerifier(function (string $url, string $method, array $headers, string $body) use ($actual): array {
            $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (($request['method'] ?? null) === 'initialize') return ['status' => 200, 'body' => json_encode(['result' => ['protocolVersion' => '2026-07-28']], JSON_THROW_ON_ERROR)];
            if (($request['method'] ?? null) === 'tools/list') return ['status' => 200, 'body' => json_encode(['result' => ['tools' => [['name' => 'nhk.documentation.bootstrap'], ['name' => 'nhk.documentation.get'], ['name' => 'nhk.documentation.list']]]], JSON_THROW_ON_ERROR)];
            $payload = ($request['params']['name'] ?? '') === 'nhk.documentation.bootstrap'
                ? $actual
                : ['documentation_version' => $actual['documentation_version'], 'manifest_hash' => $actual['manifest_hash'], 'files' => $actual['files']];
            return ['status' => 200, 'body' => json_encode(['result' => ['structuredContent' => $payload, 'isError' => false]], JSON_THROW_ON_ERROR)];
        });
    }

    /** @return array<string,mixed> */
    private function expectedBootstrap(): array
    {
        return [
            'documentation_version' => str_repeat('d', 64),
            'manifest_hash' => str_repeat('e', 64),
            'build_identity' => str_repeat('f', 64),
            'files' => [
                ['path' => 'AGENTS.md', 'sha256' => str_repeat('a', 64)],
                ['path' => 'docs/architecture/P0_DEPLOYMENT_PREFLIGHT.md', 'sha256' => str_repeat('b', 64)],
            ],
        ];
    }
}
