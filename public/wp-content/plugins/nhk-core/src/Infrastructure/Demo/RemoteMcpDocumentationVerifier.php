<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Demo;

use Closure;
use NHK\Core\Application\Demo\StageResult;
use NHK\Core\Application\Mcp\{McpReleaseIdentity, McpTransport};

/** Read-only direct MCP verifier for the deployed canonical documentation. */
final class RemoteMcpDocumentationVerifier
{
    /** @param Closure(string,string,array<string>,string): array{status:int,body:string} $request */
    public function __construct(
        private readonly Closure $request,
        private readonly ?string $authorizationHeader = null,
    ) {}

    /** @param array<string,mixed> $expectedBootstrap */
    public function verify(string $baseUrl, array $expectedBootstrap, string $expectedBuildIdentity): StageResult
    {
        $url = $this->endpoint($baseUrl);
        if ($url === null || !$this->validExpected($expectedBootstrap, $expectedBuildIdentity)) return StageResult::failed('MCP_BOOTSTRAP_UNAVAILABLE');

        try {
            $initialize = $this->call($url, 'initialize', ['protocolVersion' => McpTransport::MODERN_VERSION, 'capabilities' => [], 'clientInfo' => ['name' => 'nhk-deploy-verify', 'version' => '1.0']], 1);
            if (($initialize['protocolVersion'] ?? null) !== McpTransport::MODERN_VERSION) return StageResult::failed('MCP_BOOTSTRAP_UNAVAILABLE');

            $tools = $this->call($url, 'tools/list', [], 2);
            $toolNames = array_values(array_filter(array_map(static fn (mixed $tool): string => is_array($tool) ? (string) ($tool['name'] ?? '') : '', is_array($tools['tools'] ?? null) ? $tools['tools'] : [])));
            foreach (['nhk.documentation.bootstrap', 'nhk.documentation.get', 'nhk.documentation.list', 'nhk.docs.bootstrap', 'nhk.article.publish.review'] as $requiredTool) {
                if (!in_array($requiredTool, $toolNames, true)) return StageResult::failed('MCP_BOOTSTRAP_UNAVAILABLE');
            }

            $bootstrap = $this->tool($url, 'nhk.documentation.bootstrap', 3);
            $list = $this->tool($url, 'nhk.documentation.list', 4);
            $document = $this->tool($url, 'nhk.documentation.get', 5, ['path' => 'AGENTS.md', 'start_line' => 1, 'line_count' => 1]);
            $alias = $this->tool($url, 'nhk.docs.bootstrap', 6);
            if (!hash_equals($expectedBuildIdentity, (string) ($bootstrap['build_identity'] ?? ''))) return StageResult::blocked('DEPLOYMENT_NOT_ACTIVE');
            if (!$this->sameIdentity($bootstrap, $expectedBootstrap) || !$this->sameFiles($this->filesFromBootstrap($bootstrap), $expectedBootstrap['files'])) return StageResult::failed('DOC_MANIFEST_MISMATCH');
            if (!$this->sameIdentity($list, $expectedBootstrap) || !$this->sameFiles($list['files'] ?? null, $expectedBootstrap['files'])) return StageResult::failed('DOC_MANIFEST_MISMATCH');
            if (!$this->sameIdentity($alias, $expectedBootstrap) || ($document['path'] ?? null) !== 'AGENTS.md' || !$this->sameDocumentHash($document, $expectedBootstrap['files'], 'AGENTS.md')) return StageResult::failed('DOC_MANIFEST_MISMATCH');
            if (!$this->sameReleaseIdentity($bootstrap, $expectedBootstrap)) return StageResult::failed('RELEASE_TUPLE_MISMATCH');
            if (!$this->callableProbe($url)) return StageResult::failed('MCP_CAPABILITY_PARITY_MISMATCH');
        } catch (\Throwable) {
            return StageResult::failed('MCP_BOOTSTRAP_UNAVAILABLE');
        }

        return StageResult::pass('mcp-documentation-verified', $expectedBuildIdentity);
    }

    /** @return array<string,mixed> */
    private function call(string $url, string $method, array $params, int $id): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = ($this->request)($url, 'POST', $this->headers(), $body);
        if (!is_array($response) || (int) ($response['status'] ?? 0) < 200 || (int) ($response['status'] ?? 0) >= 300 || !is_string($response['body'] ?? null)) throw new \RuntimeException('MCP_RESPONSE_UNAVAILABLE');
        $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['result'] ?? null) || ($decoded['result']['isError'] ?? false) === true) throw new \RuntimeException('MCP_RESPONSE_INVALID');
        return $decoded['result'];
    }

    /** @return array<string,mixed> */
    private function tool(string $url, string $name, int $id, array $arguments = []): array
    {
        $result = $this->call($url, 'tools/call', ['name' => $name, 'arguments' => $arguments], $id);
        if (!is_array($result['structuredContent'] ?? null)) throw new \RuntimeException('MCP_TOOL_RESPONSE_INVALID');
        return $result['structuredContent'];
    }

    /** @return list<string> */
    private function headers(): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: ' . McpTransport::MODERN_VERSION];
        if (is_string($this->authorizationHeader) && str_starts_with($this->authorizationHeader, 'Basic ')) $headers[] = 'Authorization: ' . $this->authorizationHeader;
        return $headers;
    }

    private function endpoint(string $baseUrl): ?string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = filter_var($baseUrl, FILTER_VALIDATE_URL) !== false ? parse_url($baseUrl) : false;
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || trim((string) ($parts['host'] ?? '')) === '') return null;
        return $baseUrl . '/wp-json/nhk/v1/mcp';
    }

    /** @param array<string,mixed> $expected @return bool */
    private function validExpected(array $expected, string $buildIdentity): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', (string) ($expected['documentation_version'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/i', (string) ($expected['manifest_hash'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/i', $buildIdentity) === 1
            && is_array($expected['files'] ?? null)
            && isset($expected['runtime_version'], $expected['source_revision'], $expected['catalog_version'], $expected['resource_version'], $expected['release_identity']);
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private function sameIdentity(array $actual, array $expected): bool
    {
        return hash_equals((string) $expected['documentation_version'], (string) ($actual['documentation_version'] ?? ''))
            && hash_equals((string) $expected['manifest_hash'], (string) ($actual['manifest_hash'] ?? ''));
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private function sameReleaseIdentity(array $actual, array $expected): bool
    {
        foreach (['runtime_version', 'source_revision', 'catalog_version', 'resource_version', 'release_identity'] as $field) {
            if (!array_key_exists($field, $expected) || !array_key_exists($field, $actual) || (string) $actual[$field] !== (string) $expected[$field]) return false;
        }
        $identity = $expected;
        unset($identity['release_identity'], $identity['files'], $identity['manifest'], $identity['build_identity']);
        return McpReleaseIdentity::hash($identity) === (string) $expected['release_identity'];
    }

    private function callableProbe(string $url): bool
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'nhk.article.publish.review', 'arguments' => []]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = ($this->request)($url, 'POST', $this->headers(), $body);
        if (!is_array($response) || (int) ($response['status'] ?? 0) < 200 || (int) ($response['status'] ?? 0) >= 500 || !is_string($response['body'] ?? null)) return false;
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) return false;
        return (int) ($decoded['error']['code'] ?? 0) !== -32601;
    }

    /** @param mixed $actual @param mixed $expected */
    private function sameFiles(mixed $actual, mixed $expected): bool
    {
        $actualMap = $this->fileMap($actual);
        $expectedMap = $this->fileMap($expected);
        return $actualMap !== null && $expectedMap !== null && $actualMap === $expectedMap;
    }

    /** @param mixed $document @param mixed $files */
    private function sameDocumentHash(mixed $document, mixed $files, string $path): bool
    {
        if (!is_array($document) || !is_string($document['sha256'] ?? null)) return false;
        $map = $this->fileMap($files);
        return $map !== null && isset($map[$path]) && hash_equals($map[$path], strtolower($document['sha256']));
    }

    /** @param array<string,mixed> $bootstrap */
    private function filesFromBootstrap(array $bootstrap): mixed
    {
        return $bootstrap['files'] ?? ($bootstrap['manifest']['files'] ?? null);
    }

    /** @param mixed $files @return array<string,string>|null */
    private function fileMap(mixed $files): ?array
    {
        if (!is_array($files)) return null;
        $map = [];
        foreach ($files as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_string($entry['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/i', $entry['sha256']) !== 1 || isset($map[$entry['path']])) return null;
            $map[$entry['path']] = strtolower($entry['sha256']);
        }
        ksort($map);
        return $map;
    }
}
