<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

/** Read-only projection of file-owned canonical documentation. */
final class McpDocumentationRegistry
{
    public const MANIFEST_SCHEMA_VERSION = 1;
    public const MAX_DOCUMENT_BYTES = 524288;
    public const MAX_LINE_COUNT = 500;

    /** @var array<string,array{path:string,classification:string,status:string,domain:string}> */
    private const DOCUMENTS = [
        'agents' => ['path' => 'AGENTS.md', 'classification' => 'canonical_router', 'status' => 'ACTIVE', 'domain' => 'operator'],
        'read-first' => ['path' => 'docs/constitution/READ_FIRST.md', 'classification' => 'canonical_router', 'status' => 'ACTIVE', 'domain' => 'operator'],
        'constitution' => ['path' => 'docs/constitution/NHK_V3_CONSTITUTION.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'constitution'],
        'documentation-status-index' => ['path' => 'docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md', 'classification' => 'canonical_index', 'status' => 'ACTIVE', 'domain' => 'operator'],
        'execution-runtime-state' => ['path' => 'docs/architecture/V3_EXECUTION_STATE.md', 'classification' => 'current_evidence', 'status' => 'ACTIVE', 'domain' => 'deployment'],
        'authority' => ['path' => 'docs/architecture/02_AUTHORITY_BOUNDARY.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'authority'],
        'article-ingest' => ['path' => 'docs/architecture/ARTICLE_INGEST_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'article'],
        'article-research-preflight' => ['path' => 'docs/architecture/ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'article'],
        'article-seo' => ['path' => 'docs/seo/ARTICLE_SEO_PROJECTION_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'article'],
        'media' => ['path' => 'docs/architecture/04_MEDIA_MODEL.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'media'],
        'media-video-foundation' => ['path' => 'docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'media'],
        'admin-media-guidance' => ['path' => 'docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'admin'],
        'video' => ['path' => 'docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'video-relationships' => ['path' => 'docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'video-hub-classification' => ['path' => 'docs/architecture/VIDEO_HUB_CLASSIFICATION_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'video-youtube-source' => ['path' => 'docs/architecture/VIDEO_YOUTUBE_SOURCE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'video-seo' => ['path' => 'docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'video-workflow' => ['path' => 'docs/mcp/MCP_V3_VIDEO_WORKFLOW.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'knowledge' => ['path' => 'docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'knowledge'],
        'living-knowledge' => ['path' => 'docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'knowledge'],
        'collector-profile' => ['path' => 'docs/architecture/COLLECTOR_PROFILE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'knowledge'],
        'graph' => ['path' => 'docs/architecture/11_GRAPH_CORE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'graph'],
        'related-semantic-projection' => ['path' => 'docs/architecture/RELATED_SEMANTIC_PROJECTION_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'graph'],
        'governance' => ['path' => 'docs/architecture/16_P4_GOVERNANCE_CORE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'governance'],
        'governance-retry' => ['path' => 'docs/architecture/18_GOVERNANCE_FAILURE_AND_RETRY.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'governance'],
        'mcp' => ['path' => 'docs/mcp/MCP_V3_CONTENT_OPERATIONS.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'mcp'],
        'mcp-control-plane' => ['path' => 'docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'mcp'],
        'deployment' => ['path' => 'docs/architecture/P0_DEPLOYMENT_PREFLIGHT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'deployment'],
        'public-claim-compliance' => ['path' => 'docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'governance'],
    ];

    public function __construct(private ?string $sourceRoot = null, private ?string $runtimeVersion = null)
    {
        if ($this->sourceRoot !== null) $this->sourceRoot = rtrim($this->sourceRoot, DIRECTORY_SEPARATOR);
        $this->runtimeVersion ??= defined('NHK_CORE_VERSION') ? (string) NHK_CORE_VERSION : 'unknown';
    }

    /** @return list<string> */
    public static function documentKeys(): array { return array_keys(self::DOCUMENTS); }

    /** @return array<string,array{path:string,classification:string,status:string,domain:string}> */
    public static function documentDefinitions(): array { return self::DOCUMENTS; }

    /** @return list<string> */
    public static function documentPaths(): array { return array_values(array_map(static fn (array $definition): string => $definition['path'], self::DOCUMENTS)); }

    /** @return array<string,mixed> */
    public static function buildSnapshot(string $sourceRoot, string $destination, string $runtimeVersion, ?string $generatedAt = null): array
    {
        $sourceRoot = self::realDirectory($sourceRoot, 'DOCS_NOT_AVAILABLE');
        $destination = rtrim($destination, DIRECTORY_SEPARATOR);
        if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) throw new McpDocumentationException('DOCS_NOT_AVAILABLE');
        $entries = [];
        foreach (self::DOCUMENTS as $definition) {
            $source = self::safeFile($sourceRoot, $definition['path'], false);
            if ($source === null) throw new McpDocumentationException('DOCS_NOT_AVAILABLE');
            $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $definition['path']);
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) throw new McpDocumentationException('DOCS_NOT_AVAILABLE');
            $content = file_get_contents($source);
            if ($content === false || preg_match('//u', $content) !== 1 || strlen($content) > self::MAX_DOCUMENT_BYTES) throw new McpDocumentationException('DOC_MANIFEST_INVALID');
            if (is_file($target)) @chmod($target, 0644);
            if (file_put_contents($target, $content, LOCK_EX) === false) throw new McpDocumentationException('DOCS_NOT_AVAILABLE');
            @chmod($target, 0444);
            $entries[] = self::entry($definition, $definition['path'], $content);
        }
        usort($entries, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        $manifest = self::manifest($entries, $runtimeVersion, $generatedAt ?? self::generatedAt());
        $manifestPath = $destination . DIRECTORY_SEPARATOR . 'manifest.json';
        if (is_file($manifestPath)) @chmod($manifestPath, 0644);
        if (file_put_contents($manifestPath, self::json($manifest) . "\n", LOCK_EX) === false) throw new McpDocumentationException('DOCS_NOT_AVAILABLE');
        @chmod($manifestPath, 0444);
        return $manifest;
    }

    /** @return array<string,mixed> */
    public function get(string $path, ?int $startLine = null, ?int $lineCount = null): array
    {
        $context = $this->context();
        $entry = $this->entryForPath($path, $context['manifest']);
        $absolute = $this->resolveEntry($context['root'], $entry['path']);
        if ($absolute === null) throw new McpDocumentationException('DOC_MANIFEST_INVALID');
        $content = file_get_contents($absolute);
        $hash = is_string($content) ? hash('sha256', $content) : '';
        if (!is_string($content) || preg_match('//u', $content) !== 1 || !hash_equals((string) $entry['sha256'], $hash)) throw new McpDocumentationException('DOC_MANIFEST_INVALID');
        $startLine ??= 1;
        if ($startLine < 1) throw new McpDocumentationException('DOC_LINE_RANGE_INVALID');
        if ($lineCount !== null && ($lineCount < 1 || $lineCount > self::MAX_LINE_COUNT)) throw new McpDocumentationException('DOC_LINE_LIMIT');
        $lines = preg_split('/\R/u', $content);
        if ($lines === false) throw new McpDocumentationException('DOC_MANIFEST_INVALID');
        if ($lines !== [] && end($lines) === '') array_pop($lines);
        $total = count($lines);
        $offset = min($startLine - 1, $total);
        $selected = $lineCount === null ? array_slice($lines, $offset) : array_slice($lines, $offset, $lineCount);
        $endLine = $selected === [] ? $offset : $offset + count($selected);
        return [
            'path' => $entry['path'], 'document_key' => $this->keyForPath($entry['path']), 'status' => $entry['status'], 'domain' => $entry['domain'], 'classification' => $entry['classification'],
            'sha256' => $hash, 'document_hash' => $hash, 'documentation_version' => $context['manifest']['documentation_version'], 'manifest_hash' => $context['manifest']['manifest_hash'],
            'content' => implode("\n", $selected) . ($selected === [] ? '' : "\n"), 'start_line' => $selected === [] ? $startLine : $offset + 1, 'end_line' => $endLine, 'has_more' => $endLine < $total,
            'source_revision' => $this->sourceRevision($context['root']),
        ];
    }

    /** @return array<string,mixed> */
    public function list(?string $status = null, ?string $domain = null, ?string $pathPrefix = null): array
    {
        $manifest = $this->context()['manifest'];
        $status = $status !== null ? strtoupper(trim($status)) : null;
        $domain = $domain !== null ? trim($domain) : null;
        $pathPrefix = $pathPrefix !== null ? trim($pathPrefix) : null;
        if ($pathPrefix === '') $pathPrefix = null;
        if ($pathPrefix !== null && $this->isTraversal($pathPrefix)) throw new McpDocumentationException('DOC_PATH_TRAVERSAL_BLOCKED');
        $files = array_values(array_filter($manifest['files'], static fn (array $entry): bool => ($status === null || $entry['status'] === $status) && ($domain === null || $entry['domain'] === $domain) && ($pathPrefix === null || str_starts_with($entry['path'], $pathPrefix))));
        return ['documentation_version' => $manifest['documentation_version'], 'manifest_hash' => $manifest['manifest_hash'], 'files' => $files];
    }

    /** @return array<string,mixed> */
    public function bootstrap(): array
    {
        $context = $this->context(); $manifest = $context['manifest'];
        $bootstrapDocuments = [
            $this->get($manifest['entry_point'], 1, 240),
            $this->get($manifest['status_index'], 1, 240),
            $this->get($manifest['execution_state'], 1, 240),
        ];
        return [
            'runtime_version' => $manifest['runtime_version'], 'documentation_version' => $manifest['documentation_version'], 'manifest_hash' => $manifest['manifest_hash'], 'generated_at' => $manifest['generated_at'],
            'entry_point' => $manifest['entry_point'], 'status_index' => $manifest['status_index'], 'execution_state' => $manifest['execution_state'],
            'read_first' => $bootstrapDocuments[0]['content'], 'documentation_status_index' => $bootstrapDocuments[1]['content'], 'execution_state_content' => $bootstrapDocuments[2]['content'], 'bootstrap_documents' => $bootstrapDocuments,
            'active_documents' => $this->list('ACTIVE')['files'], 'manifest' => $this->list(),
            'required_reading' => ['agents', 'read-first', 'constitution', 'documentation-status-index'],
            'required_reading_paths' => ['AGENTS.md', 'docs/constitution/READ_FIRST.md', 'docs/constitution/NHK_V3_CONSTITUTION.md', 'docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md', 'docs/architecture/V3_EXECUTION_STATE.md'],
            // Compatibility fields for clients that consumed the pre-manifest
            // bootstrap. They are projections of the same manifest, not a
            // second documentation source.
            'documentation_revision' => $manifest['documentation_version'],
            'source_revision' => $this->sourceRevision($context['root']),
            'build_revision' => $this->sourceRevision($context['root']),
            'constitution' => ['document_key' => 'constitution', 'revision' => $this->get('docs/constitution/NHK_V3_CONSTITUTION.md')['sha256']],
            'truth_model' => ['canonical_contract' => 'what NHK V3 architecture requires', 'runtime_status' => 'what this MCP runtime currently registers; LIVE requires fresh discovery/read-back'],
            'runtime_status' => ['surface' => 'mcp', 'status' => 'registered_not_live_verified', 'registered_tools' => array_values(array_map(static fn (array $tool): string => (string) $tool['name'], McpToolCatalog::tools()))],
            'source_root_available' => true,
        ];
    }

    /** @param array<string,mixed> $checkpoint */
    public function assertCheckpoint(array $checkpoint): void
    {
        if (trim((string) ($checkpoint['manifest_hash'] ?? '')) === '' || trim((string) ($checkpoint['documentation_version'] ?? '')) === '') throw new McpDocumentationException('DOCUMENTATION_CHECKPOINT_REQUIRED');
        $manifest = $this->context()['manifest'];
        if (!hash_equals((string) $manifest['manifest_hash'], (string) $checkpoint['manifest_hash']) || !hash_equals((string) $manifest['documentation_version'], (string) $checkpoint['documentation_version'])) throw new McpDocumentationException('DOCUMENTATION_CHECKPOINT_STALE');
    }

    /** @return array{root:string,manifest:array<string,mixed>} */
    private function context(): array
    {
        foreach ($this->roots() as $candidate) {
            $candidate = rtrim($candidate, DIRECTORY_SEPARATOR); $manifest = $candidate . DIRECTORY_SEPARATOR . 'manifest.json';
            if (is_file($manifest)) return ['root' => self::realDirectory($candidate, 'DOC_MANIFEST_INVALID'), 'manifest' => $this->readManifest($manifest, $candidate)];
            if (is_dir($candidate . DIRECTORY_SEPARATOR . 'docs') || is_file($candidate . DIRECTORY_SEPARATOR . 'AGENTS.md')) return ['root' => self::realDirectory($candidate, 'DOCS_NOT_AVAILABLE'), 'manifest' => $this->manifestForSource($candidate)];
        }
        throw new McpDocumentationException('DOCS_NOT_AVAILABLE');
    }

    /** @return list<string> */
    private function roots(): array
    {
        if ($this->sourceRoot !== null) return [$this->sourceRoot];
        $repoRoot = dirname(__DIR__, 7); $pluginRoot = dirname(__DIR__, 3);
        return [$repoRoot, $pluginRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'canonical-docs', $pluginRoot . DIRECTORY_SEPARATOR . 'resources'];
    }

    /** @return array<string,mixed> */
    private function manifestForSource(string $root): array
    {
        $entries = [];
        foreach (self::DOCUMENTS as $definition) {
            $file = self::safeFile($root, $definition['path'], false); if ($file === null) throw new McpDocumentationException('DOCS_NOT_AVAILABLE');
            $content = file_get_contents($file); if (!is_string($content) || preg_match('//u', $content) !== 1 || strlen($content) > self::MAX_DOCUMENT_BYTES) throw new McpDocumentationException('DOC_MANIFEST_INVALID');
            $entries[] = self::entry($definition, $definition['path'], $content);
        }
        usort($entries, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        return self::manifest($entries, $this->runtimeVersion, self::generatedAt());
    }

    /** @return array<string,mixed> */
    private function readManifest(string $manifestPath, string $root): array
    {
        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($decoded) || (int) ($decoded['schema_version'] ?? 0) !== self::MANIFEST_SCHEMA_VERSION || !is_array($decoded['files'] ?? null) || !hash_equals((string) ($decoded['manifest_hash'] ?? ''), self::manifestHash($decoded))) throw new McpDocumentationException('DOC_MANIFEST_INVALID');
        if ((string) ($decoded['runtime_version'] ?? '') === '' || (string) $decoded['runtime_version'] !== $this->runtimeVersion) throw new McpDocumentationException('DOC_RUNTIME_MISMATCH');
        $known = array_fill_keys(self::documentPaths(), true); $seen = [];
        foreach ($decoded['files'] as $entry) {
            if (!is_array($entry) || !isset($entry['path'], $entry['sha256'], $entry['status'], $entry['domain'], $entry['classification']) || !in_array($entry['status'], ['ACTIVE', 'SUPERSEDED', 'HISTORICAL', 'DEPRECATED'], true) || !isset($known[$entry['path']]) || isset($seen[$entry['path']]) || $this->isTraversal((string) $entry['path'])) throw new McpDocumentationException('DOC_MANIFEST_INVALID');
            $seen[$entry['path']] = true; $file = $this->resolveEntry($root, (string) $entry['path']);
            if ($file === null || !hash_equals((string) $entry['sha256'], hash_file('sha256', $file) ?: '')) throw new McpDocumentationException('DOC_MANIFEST_INVALID');
        }
        if (count($seen) !== count(self::DOCUMENTS)) throw new McpDocumentationException('DOC_MANIFEST_INVALID');
        return $decoded;
    }

    /** @param array<string,mixed> $manifest */
    private static function manifestHash(array $manifest): string { unset($manifest['manifest_hash'], $manifest['generated_at']); return hash('sha256', self::json($manifest)); }

    /** @param list<array<string,mixed>> $entries @return array<string,mixed> */
    private static function manifest(array $entries, string $runtimeVersion, string $generatedAt): array
    {
        $manifest = ['schema_version' => self::MANIFEST_SCHEMA_VERSION, 'documentation_version' => hash('sha256', self::json($entries)), 'runtime_version' => $runtimeVersion, 'generated_at' => $generatedAt, 'entry_point' => 'docs/constitution/READ_FIRST.md', 'status_index' => 'docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md', 'execution_state' => 'docs/architecture/V3_EXECUTION_STATE.md', 'files' => $entries];
        $manifest['manifest_hash'] = self::manifestHash($manifest); return $manifest;
    }

    /** @param array{path:string,classification:string,status:string,domain:string} $definition @return array<string,mixed> */
    private static function entry(array $definition, string $path, string $content): array { return ['path' => $path, 'sha256' => hash('sha256', $content), 'status' => $definition['status'], 'domain' => $definition['domain'], 'classification' => $definition['classification']]; }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function entryForPath(string $path, array $manifest): array
    {
        if (isset(self::DOCUMENTS[$path])) $path = self::DOCUMENTS[$path]['path'];
        if ($this->isTraversal($path)) throw new McpDocumentationException('DOC_PATH_TRAVERSAL_BLOCKED', 'DOC_PATH_TRAVERSAL_BLOCKED (DOCUMENT_NOT_ALLOWLISTED)');
        foreach ($manifest['files'] as $entry) if (is_array($entry) && ($entry['path'] ?? '') === $path) return $entry;
        throw new McpDocumentationException('DOC_PATH_NOT_ALLOWED');
    }

    private function keyForPath(string $path): ?string { foreach (self::DOCUMENTS as $key => $definition) if ($definition['path'] === $path) return $key; return null; }
    private function resolveEntry(string $root, string $relative): ?string { return self::safeFile($root, $relative, true); }

    private static function safeFile(string $root, string $relative, bool $rejectSymlink): ?string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $relative) === 1) return null;
        $candidate = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($rejectSymlink && self::containsSymlink($root, $relative)) return null;
        $realRoot = realpath($root); $realCandidate = realpath($candidate);
        if ($realRoot === false || $realCandidate === false || !is_file($realCandidate) || !is_readable($realCandidate)) return null;
        return $realCandidate === $realRoot || !str_starts_with($realCandidate, $realRoot . DIRECTORY_SEPARATOR) ? null : $realCandidate;
    }

    private static function containsSymlink(string $root, string $relative): bool
    {
        $path = rtrim($root, DIRECTORY_SEPARATOR);
        foreach (explode('/', str_replace('\\', '/', $relative)) as $part) { $path .= DIRECTORY_SEPARATOR . $part; if (is_link($path)) return true; }
        return false;
    }

    private function isTraversal(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '\\')) return true;
        $decoded = rawurldecode($path);
        foreach ([$path, $decoded, rawurldecode($decoded)] as $value) if (in_array('..', explode('/', $value), true)) return true;
        return false;
    }

    private static function realDirectory(string $path, string $code): string { $real = realpath($path); if ($real === false || !is_dir($real)) throw new McpDocumentationException($code); return $real; }

    private function sourceRevision(string $root): ?string
    {
        if (!is_dir($root . DIRECTORY_SEPARATOR . '.git') && !is_file($root . DIRECTORY_SEPARATOR . '.git')) return null;
        $revision = function_exists('shell_exec') ? shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null') : null; $revision = is_string($revision) ? trim($revision) : '';
        return preg_match('/^[0-9a-f]{40}$/i', $revision) === 1 ? $revision : null;
    }

    private static function generatedAt(): string { $epoch = getenv('SOURCE_DATE_EPOCH'); return is_string($epoch) && ctype_digit($epoch) ? gmdate('c', (int) $epoch) : gmdate('c'); }
    /** @param array<string,mixed> $value */
    private static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
}

final class McpDocumentationException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, ?string $message = null) { parent::__construct($message ?? $reasonCode); }
    /** @return array<string,string> */
    public function toArray(): array { return ['code' => $this->reasonCode, 'reason' => $this->reasonCode]; }
}
