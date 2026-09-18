<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Mcp\McpReleaseIdentity;
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
                    ['name' => 'nhk.documentation.list'], ['name' => 'nhk.docs.bootstrap'], ['name' => 'nhk.article.publish.review'],
                ]]], JSON_THROW_ON_ERROR)],
                'tools/call' => $this->jsonResponse($this->payloadFor($expected, (string) ($request['params']['name'] ?? ''))),
                default => ['status' => 404, 'body' => ''],
            };
        });

        $result = $verifier->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('pass', $result->status, (string) $result->reasonCode);
        self::assertSame('mcp-documentation-verified', $result->identifier);
        self::assertSame(str_repeat('f', 64), $result->fingerprint);
        self::assertCount(7, $calls);
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

    public function test_manifest_only_is_not_a_canonical_bootstrap_packet(): void
    {
        $expected = $this->expectedBootstrap();
        $manifest = [
            'runtime_version' => $expected['runtime_version'],
            'source_revision' => $expected['source_revision'],
            'documentation_version' => $expected['documentation_version'],
            'manifest_hash' => $expected['manifest_hash'],
            'files' => $expected['files'],
        ];

        $result = $this->verifierFor($expected)->verify('https://demo.example', $manifest, str_repeat('f', 64));

        self::assertSame('MCP_BOOTSTRAP_UNAVAILABLE', $result->reasonCode);
    }

    public function test_optional_read_grant_is_forwarded_without_changing_the_endpoint(): void
    {
        $expected = $this->expectedBootstrap();
        $calls = [];
        $verifier = new RemoteMcpDocumentationVerifier(function (string $url, string $method, array $headers, string $body) use (&$calls, $expected): array {
            $calls[] = [$url, $method, $headers, $body];
            $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return match ($request['method'] ?? null) {
                'initialize' => ['status' => 200, 'body' => json_encode(['result' => ['protocolVersion' => '2026-07-28']], JSON_THROW_ON_ERROR)],
                'tools/list' => ['status' => 200, 'body' => json_encode(['result' => ['tools' => [['name' => 'nhk.documentation.bootstrap'], ['name' => 'nhk.documentation.get'], ['name' => 'nhk.documentation.list'], ['name' => 'nhk.docs.bootstrap'], ['name' => 'nhk.article.publish.review']]]], JSON_THROW_ON_ERROR)],
                'tools/call' => $this->jsonResponse($this->payloadFor($expected, (string) ($request['params']['name'] ?? ''))),
                default => ['status' => 404, 'body' => ''],
            };
        }, 'Basic dXNlcjpwYXNz');

        $result = $verifier->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('pass', $result->status);
        self::assertCount(7, $calls);
        foreach ($calls as $call) self::assertContains('Authorization: Basic dXNlcjpwYXNz', $call[2]);
    }

    public function test_bootstrap_accepts_the_canonical_nested_manifest_shape(): void
    {
        $expected = $this->expectedBootstrap();
        $verifier = new RemoteMcpDocumentationVerifier(function (string $url, string $method, array $headers, string $body) use ($expected): array {
            $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (($request['method'] ?? null) === 'initialize') return ['status' => 200, 'body' => json_encode(['result' => ['protocolVersion' => '2026-07-28']], JSON_THROW_ON_ERROR)];
            if (($request['method'] ?? null) === 'tools/list') return ['status' => 200, 'body' => json_encode(['result' => ['tools' => [['name' => 'nhk.documentation.bootstrap'], ['name' => 'nhk.documentation.get'], ['name' => 'nhk.documentation.list'], ['name' => 'nhk.docs.bootstrap'], ['name' => 'nhk.article.publish.review']]]], JSON_THROW_ON_ERROR)];
            if (in_array($request['params']['name'] ?? '', ['nhk.documentation.bootstrap', 'nhk.docs.bootstrap'], true)) {
                $bootstrap = $expected;
                $bootstrap['manifest'] = ['files' => $expected['files']];
                unset($bootstrap['files']);
                return $this->jsonResponse($bootstrap);
            }
            return $this->jsonResponse($this->payloadFor($expected, (string) ($request['params']['name'] ?? '')));
        });

        self::assertSame('pass', $verifier->verify('https://demo.example', $expected, str_repeat('f', 64))->status);
    }

    public function test_registry_bootstrap_expected_payload_uses_nested_manifest_files(): void
    {
        $expected = $this->expectedBootstrap();
        $files = $expected['files'];
        $expected['manifest'] = ['files' => $files];
        $expected['entry_point'] = 'AGENTS.md';
        $expected['read_first'] = 'projection content';
        $expected['runtime_status'] = ['surface' => 'mcp'];
        unset($expected['files']);
        $actual = $expected;
        $actual['manifest'] = ['files' => $files];
        $verifier = new RemoteMcpDocumentationVerifier(function (string $url, string $method, array $headers, string $body) use ($actual, $files): array {
            $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (($request['method'] ?? null) === 'initialize') return ['status' => 200, 'body' => json_encode(['result' => ['protocolVersion' => '2026-07-28']], JSON_THROW_ON_ERROR)];
            if (($request['method'] ?? null) === 'tools/list') return ['status' => 200, 'body' => json_encode(['result' => ['tools' => [['name' => 'nhk.documentation.bootstrap'], ['name' => 'nhk.documentation.get'], ['name' => 'nhk.documentation.list'], ['name' => 'nhk.docs.bootstrap'], ['name' => 'nhk.article.publish.review']]]], JSON_THROW_ON_ERROR)];
            $tool = (string) ($request['params']['name'] ?? '');
            if (in_array($tool, ['nhk.documentation.bootstrap', 'nhk.docs.bootstrap'], true)) return $this->jsonResponse($actual);
            if ($tool === 'nhk.documentation.list') return $this->jsonResponse(['documentation_version' => $actual['documentation_version'], 'manifest_hash' => $actual['manifest_hash'], 'files' => $files]);
            return $this->jsonResponse($this->documentPayload(['documentation_version' => $actual['documentation_version'], 'manifest_hash' => $actual['manifest_hash'], 'files' => $files]));
        });

        $result = $verifier->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('pass', $result->status, (string) $result->reasonCode);
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

    public function test_source_revision_mismatch_is_rejected_as_a_release_tuple_mismatch(): void
    {
        $expected = $this->expectedBootstrap();
        $actual = $expected;
        $actual['source_revision'] = str_repeat('0', 40);
        $verifier = $this->verifierFor($actual);

        $result = $verifier->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('RELEASE_TUPLE_MISMATCH', $result->reasonCode);
    }

    public function test_runtime_environment_is_not_taken_from_local_artifact_expectation(): void
    {
        $expected = $this->expectedBootstrap();
        $expected['environment'] = 'unknown';
        $expected['semantic_write_policy'] = 'READ_ONLY';
        $expected['project_build_enabled'] = false;

        $actual = $this->expectedBootstrap();
        $actual['release_identity'] = McpReleaseIdentity::hash(array_diff_key($actual, ['files' => true]));

        $result = $this->verifierFor($actual)->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('pass', $result->status, (string) $result->reasonCode);
    }

    public function test_tampered_runtime_release_identity_fails_closed(): void
    {
        $expected = $this->expectedBootstrap();
        $actual = $expected;
        $actual['release_identity'] = str_repeat('0', 64);

        $result = $this->verifierFor($actual)->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('RELEASE_TUPLE_MISMATCH', $result->reasonCode);
    }

    /** @dataProvider artifactIdentityMismatchProvider */
    public function test_artifact_release_fields_must_match_the_immutable_expectation(string $field): void
    {
        $expected = $this->expectedBootstrap();
        $actual = $expected;
        $actual[$field] = $field === 'source_revision' ? str_repeat('0', 40) : str_repeat('0', 64);

        $result = $this->verifierFor($actual)->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('RELEASE_TUPLE_MISMATCH', $result->reasonCode);
    }

    public static function artifactIdentityMismatchProvider(): array
    {
        return array_map(static fn (string $field): array => [$field], ['source_revision', 'catalog_version', 'resource_version']);
    }

    public function test_missing_source_revision_is_not_a_valid_expected_release(): void
    {
        $expected = $this->expectedBootstrap();
        $expected['source_revision'] = null;

        $result = $this->verifierFor($expected)->verify('https://demo.example', $expected, str_repeat('f', 64));

        self::assertSame('MCP_BOOTSTRAP_UNAVAILABLE', $result->reasonCode);
    }

    public function test_unavailable_or_malformed_mcp_fails_closed(): void
    {
        $verifier = new RemoteMcpDocumentationVerifier(static fn (): array => ['status' => 502, 'body' => 'upstream unavailable']);

        $result = $verifier->verify('https://demo.example', $this->expectedBootstrap(), str_repeat('f', 64));

        self::assertSame('MCP_BOOTSTRAP_UNAVAILABLE', $result->reasonCode);
    }

    public function test_unknown_publication_review_dispatch_is_not_accepted_as_a_verified_release(): void
    {
        $expected = $this->expectedBootstrap();
        $verifier = new RemoteMcpDocumentationVerifier(function (string $url, string $method, array $headers, string $body) use ($expected): array {
            $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (($request['method'] ?? null) === 'initialize') return ['status' => 200, 'body' => json_encode(['result' => ['protocolVersion' => '2026-07-28']], JSON_THROW_ON_ERROR)];
            if (($request['method'] ?? null) === 'tools/list') return ['status' => 200, 'body' => json_encode(['result' => ['tools' => [
                ['name' => 'nhk.documentation.bootstrap'], ['name' => 'nhk.documentation.get'], ['name' => 'nhk.documentation.list'], ['name' => 'nhk.docs.bootstrap'], ['name' => 'nhk.article.publish.review'],
            ]]], JSON_THROW_ON_ERROR)];
            if (($request['params']['name'] ?? '') === 'nhk.article.publish.review') return ['status' => 200, 'body' => json_encode(['jsonrpc' => '2.0', 'id' => 5, 'error' => ['code' => -32601, 'message' => 'Unknown tool']], JSON_THROW_ON_ERROR)];
            return $this->jsonResponse($this->payloadFor($expected, (string) ($request['params']['name'] ?? '')));
        });

        self::assertSame('MCP_CAPABILITY_PARITY_MISMATCH', $verifier->verify('https://demo.example', $expected, str_repeat('f', 64))->reasonCode);
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
            if (($request['method'] ?? null) === 'tools/list') return ['status' => 200, 'body' => json_encode(['result' => ['tools' => [['name' => 'nhk.documentation.bootstrap'], ['name' => 'nhk.documentation.get'], ['name' => 'nhk.documentation.list'], ['name' => 'nhk.docs.bootstrap'], ['name' => 'nhk.article.publish.review']]]], JSON_THROW_ON_ERROR)];
            $payload = ($request['params']['name'] ?? '') === 'nhk.documentation.bootstrap' ? $actual : $this->payloadFor($actual, (string) ($request['params']['name'] ?? ''));
            return ['status' => 200, 'body' => json_encode(['result' => ['structuredContent' => $payload, 'isError' => false]], JSON_THROW_ON_ERROR)];
        });
    }

    /** @param array<string,mixed> $expected @return array<string,mixed> */
    private function payloadFor(array $expected, string $tool): array
    {
        if ($tool === 'nhk.documentation.get') return $this->documentPayload($expected);
        return ['documentation_version' => $expected['documentation_version'], 'manifest_hash' => $expected['manifest_hash'], 'files' => $expected['files']] + $expected;
    }

    /** @param array<string,mixed> $expected @return array<string,mixed> */
    private function documentPayload(array $expected): array
    {
        return ['path' => 'AGENTS.md', 'document_key' => 'agents', 'sha256' => $expected['files'][0]['sha256'], 'documentation_version' => $expected['documentation_version'], 'manifest_hash' => $expected['manifest_hash']];
    }

    /** @return array<string,mixed> */
    private function expectedBootstrap(): array
    {
        return [
            'runtime_version' => '0.1.0',
            'source_revision' => str_repeat('1', 40),
            'documentation_version' => str_repeat('d', 64),
            'manifest_hash' => str_repeat('e', 64),
            'build_identity' => str_repeat('f', 64),
            'catalog_version' => str_repeat('a', 64),
            'resource_version' => str_repeat('b', 64),
            'environment' => 'staging',
            'semantic_write_policy' => 'PROJECT_BUILD_ONLY',
            'project_build_enabled' => true,
            'files' => [
                ['path' => 'AGENTS.md', 'sha256' => str_repeat('a', 64)],
                ['path' => 'docs/architecture/P0_DEPLOYMENT_PREFLIGHT.md', 'sha256' => str_repeat('b', 64)],
            ],
        ] + ['release_identity' => McpReleaseIdentity::hash([
            'runtime_version' => '0.1.0', 'source_revision' => str_repeat('1', 40),
            'documentation_version' => str_repeat('d', 64), 'manifest_hash' => str_repeat('e', 64),
            'build_identity' => str_repeat('f', 64),
            'catalog_version' => str_repeat('a', 64), 'resource_version' => str_repeat('b', 64),
            'environment' => 'staging', 'semantic_write_policy' => 'PROJECT_BUILD_ONLY', 'project_build_enabled' => true,
        ])];
    }
}
