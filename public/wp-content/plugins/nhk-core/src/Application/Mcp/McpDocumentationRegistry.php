<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use RuntimeException;

/** Read-only allowlisted access to the canonical NHK V3 documentation. */
final class McpDocumentationRegistry
{
    private const MAX_DOCUMENT_BYTES = 524288;

    /** @var array<string,array{path:string,classification:string}> */
    private const DOCUMENTS = [
        'agents' => ['path' => 'AGENTS.md', 'classification' => 'canonical_router'],
        'read-first' => ['path' => 'docs/constitution/READ_FIRST.md', 'classification' => 'canonical_router'],
        'constitution' => ['path' => 'docs/constitution/NHK_V3_CONSTITUTION.md', 'classification' => 'canonical_contract'],
        'documentation-status-index' => ['path' => 'docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md', 'classification' => 'canonical_index'],
        'execution-runtime-state' => ['path' => 'docs/architecture/V3_EXECUTION_STATE.md', 'classification' => 'current_evidence'],
        'authority' => ['path' => 'docs/architecture/02_AUTHORITY_BOUNDARY.md', 'classification' => 'canonical_contract'],
        'knowledge' => ['path' => 'docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md', 'classification' => 'canonical_contract'],
        'graph' => ['path' => 'docs/architecture/11_GRAPH_CORE_CONTRACT.md', 'classification' => 'canonical_contract'],
        'governance' => ['path' => 'docs/architecture/16_P4_GOVERNANCE_CORE_CONTRACT.md', 'classification' => 'canonical_contract'],
        'media' => ['path' => 'docs/architecture/04_MEDIA_MODEL.md', 'classification' => 'canonical_contract'],
        'media-video-foundation' => ['path' => 'docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md', 'classification' => 'canonical_contract'],
        'admin-media-guidance' => ['path' => 'docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md', 'classification' => 'canonical_contract'],
        'mcp' => ['path' => 'docs/mcp/MCP_V3_CONTENT_OPERATIONS.md', 'classification' => 'canonical_contract'],
        'mcp-control-plane' => ['path' => 'docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md', 'classification' => 'canonical_contract'],
        'video' => ['path' => 'docs/mcp/MCP_V3_VIDEO_WORKFLOW.md', 'classification' => 'canonical_contract'],
    ];

    public function __construct(private ?string $sourceRoot = null)
    {
        if ($this->sourceRoot !== null) $this->sourceRoot = rtrim($this->sourceRoot, DIRECTORY_SEPARATOR);
    }

    /** @return list<string> */
    public static function documentKeys(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    /** @return array<string,mixed> */
    public function get(string $key): array
    {
        $definition = self::DOCUMENTS[$key] ?? null;
        if ($definition === null) throw new RuntimeException('DOCUMENT_NOT_ALLOWLISTED');
        $root = $this->resolveRoot($definition['path']);
        if ($root === null) throw new RuntimeException('DOCUMENT_UNAVAILABLE');
        $absolute = $root . DIRECTORY_SEPARATOR . $definition['path'];
        $size = filesize($absolute);
        if ($size === false || $size > self::MAX_DOCUMENT_BYTES) throw new RuntimeException('DOCUMENT_SIZE_LIMIT');
        $content = file_get_contents($absolute);
        if ($content === false || preg_match('//u', $content) !== 1) throw new RuntimeException('DOCUMENT_INVALID_UTF8');
        return [
            'document_key' => $key,
            'relative_path' => $definition['path'],
            'classification' => $definition['classification'],
            'content' => $content,
            'document_hash' => hash('sha256', $content),
            'documentation_revision' => $this->documentationRevision($root),
            'source_revision' => $this->sourceRevision($root),
            'build_revision' => defined('NHK_V3_BUILD_REVISION') ? (string) NHK_V3_BUILD_REVISION : null,
            'generated_at' => gmdate('c'),
        ];
    }

    /** @return array<string,mixed> */
    public function bootstrap(): array
    {
        $root = $this->resolveRoot('docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md');
        $index = $this->get('documentation-status-index');
        $manifest = McpCapabilityManifest::all();
        $registeredTools = array_map(static fn (array $tool): string => (string) $tool['name'], McpToolCatalog::tools());
        $governed = array_values(array_filter($registeredTools, static fn (string $name): bool => McpToolCatalog::isGoverned($name)));
        return [
            'documentation_revision' => $index['documentation_revision'],
            'source_revision' => $index['source_revision'],
            'build_revision' => $index['build_revision'],
            'generated_at' => $index['generated_at'],
            'constitution' => ['document_key' => 'constitution', 'revision' => $this->get('constitution')['document_hash']],
            'required_reading' => ['agents', 'read-first', 'constitution', 'documentation-status-index'],
            'canonical_documentation_index' => ['document_key' => 'documentation-status-index', 'classification' => 'canonical_index'],
            'current_contracts' => [
                'Authority' => 'authority', 'Knowledge' => 'knowledge', 'Source/Evidence' => 'knowledge',
                'Graph' => 'graph', 'Governance' => 'governance', 'Media' => 'media', 'MCP' => 'mcp',
                'Product/Specimen' => 'constitution',
            ],
            'runtime_status' => [
                'surface' => 'mcp', 'status' => 'registered_not_live_verified',
                'tool_count' => count($registeredTools), 'read_tool_count' => count($registeredTools) - count($governed),
                'governed_tool_count' => count($governed), 'registered_tools' => $registeredTools,
                'content_kind_manifest' => array_map(static fn (array $entry): array => [
                    'reads' => $entry['reads'], 'writes' => $entry['writes'], 'unsupported' => $entry['unsupported'],
                ], $manifest),
            ],
            'registry_gaps' => [
                ['key' => 'public_identity_runtime_activation', 'status' => 'documented_gap', 'source' => 'documentation-status-index'],
                ['key' => 'product_specimen_relation', 'status' => 'documented_gap', 'source' => 'documentation-status-index'],
                ['key' => 'classification_predicate', 'status' => 'documented_gap', 'source' => 'documentation-status-index'],
                ['key' => 'media_living_knowledge_adapter', 'status' => 'documented_gap', 'source' => 'documentation-status-index'],
                ['key' => 'live_target_discovery_readback', 'status' => 'environment_gate', 'source' => 'documentation-status-index'],
            ],
            'truth_model' => ['canonical_contract' => 'what NHK V3 architecture requires', 'runtime_status' => 'what this MCP runtime currently registers; LIVE requires fresh discovery/read-back'],
            'source_root_available' => $root !== null,
        ];
    }

    private function resolveRoot(string $relativePath): ?string
    {
        foreach ($this->roots() as $root) {
            $root = rtrim($root, DIRECTORY_SEPARATOR);
            $candidate = $root . DIRECTORY_SEPARATOR . $relativePath;
            $realRoot = realpath($root);
            $realCandidate = realpath($candidate);
            if ($realRoot === false || $realCandidate === false || !is_file($realCandidate)) continue;
            if ($realCandidate === $realRoot || !str_starts_with($realCandidate, $realRoot . DIRECTORY_SEPARATOR)) continue;
            return $realRoot;
        }
        return null;
    }

    /** @return list<string> */
    private function roots(): array
    {
        if ($this->sourceRoot !== null) return [$this->sourceRoot];
        $repoRoot = dirname(__DIR__, 7);
        $artifactRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources';
        return [$repoRoot, $artifactRoot];
    }

    private function documentationRevision(string $root): string
    {
        $hash = hash_init('sha256');
        foreach (self::documentKeys() as $key) {
            $path = $root . DIRECTORY_SEPARATOR . self::DOCUMENTS[$key]['path'];
            $content = is_file($path) ? file_get_contents($path) : false;
            if ($content !== false) { hash_update($hash, $key . "\0" . $content); }
        }
        return hash_final($hash);
    }

    private function sourceRevision(string $root): ?string
    {
        $repo = $root;
        if (!is_dir($repo . DIRECTORY_SEPARATOR . '.git') && !is_file($repo . DIRECTORY_SEPARATOR . '.git')) return null;
        $revision = function_exists('shell_exec') ? shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse HEAD 2>/dev/null') : null;
        $revision = is_string($revision) ? trim($revision) : '';
        return preg_match('/^[0-9a-f]{40}$/i', $revision) === 1 ? $revision : null;
    }
}
