<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

/** Read-only projection of file-owned canonical documentation. */
final class McpDocumentationRegistry
{
    public const MANIFEST_SCHEMA_VERSION = 2;
    public const MAX_DOCUMENT_BYTES = 1048576;
    public const MAX_LINE_COUNT = 500;

    /** @var array<string,array{path:string,classification:string,status:string,domain:string}> */
    private const DOCUMENTS = [
        'agents' => ['path' => 'AGENTS.md', 'classification' => 'canonical_router', 'status' => 'ACTIVE', 'domain' => 'operator'],
        'read-first' => ['path' => 'docs/constitution/READ_FIRST.md', 'classification' => 'canonical_router', 'status' => 'ACTIVE', 'domain' => 'operator'],
        'constitution' => ['path' => 'docs/constitution/NHK_V3_CONSTITUTION.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'constitution'],
        'documentation-status-index' => ['path' => 'docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md', 'classification' => 'canonical_index', 'status' => 'ACTIVE', 'domain' => 'operator'],
        'execution-runtime-state' => ['path' => 'docs/architecture/V3_EXECUTION_STATE.md', 'classification' => 'current_evidence', 'status' => 'ACTIVE', 'domain' => 'deployment'],
        'authority' => ['path' => 'docs/architecture/02_AUTHORITY_BOUNDARY.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'authority'],
        'authority-core' => ['path' => 'docs/architecture/13_AUTHORITY_CORE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'authority'],
        'canonical-domain-foundation' => ['path' => 'docs/architecture/21_P5_CANONICAL_DOMAIN_FOUNDATION.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'authority'],
        'entity-profile-clock-type' => ['path' => 'docs/architecture/ENTITY_PROFILE_CLOCK_TYPE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'authority'],
        'clock-type-ecosystem' => ['path' => 'docs/architecture/CLOCK_TYPE_ECOSYSTEM_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'authority'],
        'public-brand-naming' => ['path' => 'docs/architecture/PUBLIC_BRAND_NAMING_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'authority'],
        'public-entity-dossier' => ['path' => 'docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'authority'],
        'public-identity-matrix' => ['path' => 'docs/architecture/V3_PUBLIC_ENTITY_IDENTITY_MATRIX.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'authority'],
        'public-route-audit' => ['path' => 'docs/architecture/V3_PUBLIC_ROUTE_AUDIT.md', 'classification' => 'current_evidence', 'status' => 'ACTIVE', 'domain' => 'authority'],
        'shared-feed-ordering' => ['path' => 'docs/architecture/SHARED_FEED_ORDERING_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'presentation'],
        'article-ingest' => ['path' => 'docs/architecture/ARTICLE_INGEST_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'article'],
        'article-research-preflight' => ['path' => 'docs/architecture/ARTICLE_SEMANTIC_SEO_RESEARCH_PREFLIGHT_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'article'],
        'article-seo' => ['path' => 'docs/seo/ARTICLE_SEO_PROJECTION_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'article'],
        'media' => ['path' => 'docs/architecture/04_MEDIA_MODEL.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'media'],
        'visual-support-requirement' => ['path' => 'docs/architecture/VISUAL_SUPPORT_REQUIREMENT_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'media'],
        'media-video-foundation' => ['path' => 'docs/architecture/22_P6_MEDIA_VIDEO_FOUNDATION.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'media'],
        'admin-media-guidance' => ['path' => 'docs/architecture/ADMIN_MEDIA_INPUT_GUIDANCE.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'admin'],
        'video' => ['path' => 'docs/architecture/VIDEO_SEMANTIC_INGEST_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'video-relationships' => ['path' => 'docs/architecture/VIDEO_RELATIONSHIP_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'video-hub-classification' => ['path' => 'docs/architecture/VIDEO_HUB_CLASSIFICATION_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'video-youtube-source' => ['path' => 'docs/architecture/VIDEO_YOUTUBE_SOURCE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'video-seo' => ['path' => 'docs/seo/VIDEO_SEO_PROJECTION_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'video-workflow' => ['path' => 'docs/mcp/MCP_V3_VIDEO_WORKFLOW.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'video'],
        'knowledge' => ['path' => 'docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'knowledge'],
        'dictionary-lexical-knowledge' => ['path' => 'docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'knowledge'],
        'living-knowledge' => ['path' => 'docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'knowledge'],
        'collector-profile' => ['path' => 'docs/architecture/COLLECTOR_PROFILE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'knowledge'],
        'graph' => ['path' => 'docs/architecture/11_GRAPH_CORE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'graph'],
        'related-semantic-projection' => ['path' => 'docs/architecture/RELATED_SEMANTIC_PROJECTION_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'graph'],
        'governance' => ['path' => 'docs/architecture/16_P4_GOVERNANCE_CORE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'governance'],
        'governance-retry' => ['path' => 'docs/architecture/18_GOVERNANCE_FAILURE_AND_RETRY.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'governance'],
        'mcp' => ['path' => 'docs/mcp/MCP_V3_CONTENT_OPERATIONS.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'mcp'],
        'mcp-control-plane' => ['path' => 'docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'mcp'],
        'mcp-ability-exposure-history' => ['path' => 'docs/mcp/MCP_V3_ABILITY_EXPOSURE.md', 'classification' => 'historical_evidence', 'status' => 'HISTORICAL', 'domain' => 'mcp'],
        'deployment' => ['path' => 'docs/architecture/P0_DEPLOYMENT_PREFLIGHT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'deployment'],
        'snapshot-recovery' => ['path' => 'docs/architecture/V3_SNAPSHOT_RECOVERY_RUNTIME.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'deployment'],
        'public-claim-compliance' => ['path' => 'docs/compliance/PUBLIC_CLAIM_ADVERTISING_COMPLIANCE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'governance'],
        'parity-matrix' => ['path' => 'docs/architecture/V2_V3_PARITY_MATRIX.md', 'classification' => 'current_evidence', 'status' => 'ACTIVE', 'domain' => 'operator'],
        'brand-relationship-matrix' => ['path' => 'docs/architecture/V3_BRAND_RELATIONSHIP_MATRIX.md', 'classification' => 'current_evidence', 'status' => 'ACTIVE', 'domain' => 'operator'],
        'frontend-route-inventory' => ['path' => 'docs/architecture/V3_FRONTEND_ROUTE_INVENTORY.md', 'classification' => 'current_evidence', 'status' => 'ACTIVE', 'domain' => 'operator'],
        'seo-entity' => ['path' => 'docs/seo/ENTITY_SEO_PROJECTION_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'seo'],
        'seo-media-image' => ['path' => 'docs/seo/MEDIA_IMAGE_SEO_PROJECTION_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'seo'],
        'seo-core' => ['path' => 'docs/seo/NHK_V3_SEO_CORE_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'seo'],
        'public-url-slug' => ['path' => 'docs/seo/PUBLIC_URL_SLUG_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'seo'],
        'sitemap-indexability' => ['path' => 'docs/seo/SITEMAP_INDEXABILITY_CONTRACT.md', 'classification' => 'canonical_contract', 'status' => 'ACTIVE', 'domain' => 'seo'],
    ];

    public function __construct(private ?string $sourceRoot = null, private ?string $runtimeVersion = null, private ?\NHK\Core\Application\Runtime\SemanticWritePolicyResolver $semanticWritePolicy = null)
    {
        if ($this->sourceRoot !== null) $this->sourceRoot = rtrim($this->sourceRoot, DIRECTORY_SEPARATOR);
        $this->runtimeVersion ??= defined('NHK_CORE_VERSION') ? (string) NHK_CORE_VERSION : 'unknown';
        $this->semanticWritePolicy ??= new \NHK\Core\Application\Runtime\SemanticWritePolicyResolver();
    }

    /** @return list<string> */
    public static function documentKeys(): array { return array_keys(self::DOCUMENTS); }

    /** @return array<string,array{path:string,classification:string,status:string,domain:string}> */
    public static function documentDefinitions(): array { return self::DOCUMENTS; }

    /** @return list<string> */
    public static function documentPaths(): array { return array_values(array_map(static fn (array $definition): string => $definition['path'], self::DOCUMENTS)); }

    /**
     * Resolve the immutable source identity from the checkout that owns docs/.
     * The Git checkout is the only source of this identity; callers may not
     * supply a replacement revision that is not the checkout's current HEAD.
     */
    public static function sourceRevision(string $sourceRoot): string
    {
        $sourceRoot = self::realDirectory($sourceRoot, 'DOCS_NOT_AVAILABLE');
        $revision = self::readSourceRevision($sourceRoot);
        if ($revision === null) throw new McpDocumentationException('DOC_SOURCE_REVISION_UNAVAILABLE', null, ['source_root' => $sourceRoot]);
        return $revision;
    }

    /** @return array<string,mixed> */
    public static function buildSnapshot(string $sourceRoot, string $destination, string $runtimeVersion, ?string $generatedAt = null, ?string $sourceRevision = null): array
    {
        $sourceRoot = self::realDirectory($sourceRoot, 'DOCS_NOT_AVAILABLE');
        $checkoutRevision = self::sourceRevision($sourceRoot);
        if ($sourceRevision !== null && (!preg_match('/^[0-9a-f]{40}$/i', $sourceRevision) || !hash_equals($checkoutRevision, strtolower($sourceRevision)))) {
            throw new McpDocumentationException('DOC_SOURCE_REVISION_MISMATCH', null, ['expected' => $checkoutRevision, 'actual' => $sourceRevision]);
        }
        $destination = rtrim($destination, DIRECTORY_SEPARATOR);
        if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) throw new McpDocumentationException('DOCS_NOT_AVAILABLE');
        $entries = [];
        foreach (self::DOCUMENTS as $key => $definition) {
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
            $entries[] = self::entry($key, $definition, $definition['path'], $content);
        }
        usort($entries, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        $manifest = self::manifest($entries, $runtimeVersion, $generatedAt ?? self::generatedAt(), $checkoutRevision);
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
        if ($absolute === null) self::invalidManifest('document_file_missing_or_unsafe', ['path' => $entry['path']]);
        $content = file_get_contents($absolute);
        $hash = is_string($content) ? hash('sha256', $content) : '';
        if (!is_string($content) || preg_match('//u', $content) !== 1 || !hash_equals((string) $entry['sha256'], $hash)) self::invalidManifest('document_hash_mismatch', ['path' => $entry['path']]);
        $startLine ??= 1;
        if ($startLine < 1) throw new McpDocumentationException('DOC_LINE_RANGE_INVALID');
        if ($lineCount !== null && ($lineCount < 1 || $lineCount > self::MAX_LINE_COUNT)) throw new McpDocumentationException('DOC_LINE_LIMIT');
        $lines = preg_split('/\R/u', $content);
        if ($lines === false) self::invalidManifest('document_line_split_failed', ['path' => $entry['path']]);
        if ($lines !== [] && end($lines) === '') array_pop($lines);
        $total = count($lines);
        $offset = min($startLine - 1, $total);
        $selected = $lineCount === null ? array_slice($lines, $offset) : array_slice($lines, $offset, $lineCount);
        $endLine = $selected === [] ? $offset : $offset + count($selected);
        return [
            'path' => $entry['path'], 'document_key' => $entry['document_key'] ?? $this->keyForPath($entry['path']), 'status' => $entry['status'], 'domain' => $entry['domain'], 'classification' => $entry['classification'],
            'sha256' => $hash, 'document_hash' => $hash, 'documentation_version' => $context['manifest']['documentation_version'], 'manifest_hash' => $context['manifest']['manifest_hash'],
            'content' => implode("\n", $selected) . ($selected === [] ? '' : "\n"), 'start_line' => $selected === [] ? $startLine : $offset + 1, 'end_line' => $endLine, 'has_more' => $endLine < $total,
            'source_revision' => $context['manifest']['source_revision'] ?? null,
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
    public function runtimeIdentity(): array
    {
        $manifest = $this->context()['manifest'];
        $identity = [
            'environment' => $this->semanticWritePolicy->environment(),
            'semantic_write_policy' => $this->semanticWritePolicy->resolve()->value,
            'project_build_enabled' => $this->semanticWritePolicy->projectBuildEnabled(),
            'source_revision' => $manifest['source_revision'] ?? null,
            'runtime_version' => $manifest['runtime_version'],
            'build_identity' => $this->buildIdentity(),
            'documentation_version' => $manifest['documentation_version'],
            'manifest_hash' => $manifest['manifest_hash'],
        ];
        $identity['catalog_version'] = McpReleaseIdentity::catalogVersion();
        $identity['resource_version'] = McpReleaseIdentity::resourceVersion();
        $identity['release_identity'] = McpReleaseIdentity::hash($identity);
        return $identity;
    }

    /** @return array<string,mixed> */
    public function bootstrap(): array
    {
        $context = $this->context(); $manifest = $context['manifest']; $identity = $this->runtimeIdentity();
        $bootstrapDocuments = [
            $this->get($manifest['entry_point'], 1, 240),
            $this->get($manifest['status_index'], 1, 240),
            $this->get($manifest['execution_state'], 1, 240),
        ];
        return [
            'runtime_version' => $manifest['runtime_version'], 'source_revision' => $identity['source_revision'], 'documentation_version' => $manifest['documentation_version'], 'manifest_hash' => $manifest['manifest_hash'], 'generated_at' => $manifest['generated_at'], 'build_identity' => $identity['build_identity'], 'catalog_version' => $identity['catalog_version'], 'resource_version' => $identity['resource_version'], 'release_identity' => $identity['release_identity'],
            'environment' => $identity['environment'], 'semantic_write_policy' => $identity['semantic_write_policy'], 'project_build_enabled' => $identity['project_build_enabled'],
            'entry_point' => $manifest['entry_point'], 'status_index' => $manifest['status_index'], 'execution_state' => $manifest['execution_state'],
            'read_first' => $bootstrapDocuments[0]['content'], 'documentation_status_index' => $bootstrapDocuments[1]['content'], 'execution_state_content' => $bootstrapDocuments[2]['content'], 'bootstrap_documents' => $bootstrapDocuments,
            'active_documents' => $this->list('ACTIVE')['files'], 'manifest' => $this->list(),
            'required_reading' => ['agents', 'read-first', 'constitution', 'documentation-status-index'],
            'required_reading_paths' => ['AGENTS.md', 'docs/constitution/READ_FIRST.md', 'docs/constitution/NHK_V3_CONSTITUTION.md', 'docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md', 'docs/architecture/V3_EXECUTION_STATE.md'],
            // Compatibility fields for clients that consumed the pre-manifest
            // bootstrap. They are projections of the same manifest, not a
            // second documentation source.
            'documentation_revision' => $manifest['documentation_version'],
            'source_revision' => $identity['source_revision'],
            'build_revision' => $identity['source_revision'],
            'constitution' => ['document_key' => 'constitution', 'revision' => $this->get('docs/constitution/NHK_V3_CONSTITUTION.md')['sha256']],
            'truth_model' => ['canonical_contract' => 'what NHK V3 architecture requires', 'runtime_status' => 'what this MCP runtime currently registers; LIVE requires fresh discovery/read-back'],
            'runtime_status' => ['surface' => 'mcp', 'status' => 'registered_not_live_verified', 'registered_tools' => McpToolCatalog::names()],
            'mcp_capability_parity' => McpAbilityRegistration::callableParity(),
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
        // A deployed plugin carries an immutable, manifest-verified snapshot.
        // Prefer it over a possibly stale repository checkout at the hosting
        // root; deployment transfers the plugin artifact, not that checkout.
        $snapshot = $pluginRoot . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'canonical-docs';
        // The runtime version is not a source-selection signal. A deployed
        // plugin may be loaded before its version constant is defined (for
        // example through an Ability/bridge bootstrap), but it must still
        // read the same immutable snapshot as the normal MCP transport.
        return [$snapshot, $repoRoot, $pluginRoot . DIRECTORY_SEPARATOR . 'resources'];
    }

    /** @return array<string,mixed> */
    private function manifestForSource(string $root): array
    {
        $entries = [];
        foreach (self::DOCUMENTS as $key => $definition) {
            $file = self::safeFile($root, $definition['path'], false); if ($file === null) throw new McpDocumentationException('DOCS_NOT_AVAILABLE');
            $content = file_get_contents($file); if (!is_string($content) || preg_match('//u', $content) !== 1 || strlen($content) > self::MAX_DOCUMENT_BYTES) throw new McpDocumentationException('DOC_MANIFEST_INVALID');
            $entries[] = self::entry($key, $definition, $definition['path'], $content);
        }
        usort($entries, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        return self::manifest($entries, $this->runtimeVersion, self::generatedAt(), self::sourceRevision($root));
    }

    /** @return array<string,mixed> */
    private function readManifest(string $manifestPath, string $root): array
    {
        $raw = file_get_contents($manifestPath);
        if (!is_string($raw)) self::invalidManifest('manifest_unreadable');
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::invalidManifest('manifest_json_invalid');
        }
        if (!is_array($decoded)) self::invalidManifest('manifest_shape');

        $required = ['schema_version', 'documentation_version', 'runtime_version', 'source_revision', 'generated_at', 'entry_point', 'status_index', 'execution_state', 'files', 'manifest_hash'];
        $missing = array_values(array_diff($required, array_keys($decoded)));
        if ($missing !== []) self::invalidManifest('manifest_field_missing', ['fields' => $missing]);
        $unexpected = array_values(array_diff(array_keys($decoded), $required));
        if ($unexpected !== []) self::invalidManifest('manifest_field_unexpected', ['fields' => $unexpected]);
        if ((int) $decoded['schema_version'] !== self::MANIFEST_SCHEMA_VERSION) self::invalidManifest('schema_version_mismatch', ['expected' => self::MANIFEST_SCHEMA_VERSION, 'actual' => $decoded['schema_version']]);
        if (!is_string($decoded['documentation_version']) || preg_match('/^[a-f0-9]{64}$/i', $decoded['documentation_version']) !== 1) self::invalidManifest('documentation_version_invalid');
        if (!is_string($decoded['runtime_version']) || $decoded['runtime_version'] === '') self::invalidManifest('runtime_version_missing');
        if ($this->runtimeVersion !== 'unknown' && $decoded['runtime_version'] !== $this->runtimeVersion) throw new McpDocumentationException('DOC_RUNTIME_MISMATCH', null, ['diagnostic' => 'runtime_version_mismatch', 'expected' => $this->runtimeVersion, 'actual' => $decoded['runtime_version']]);
        // An early bridge bootstrap may not have loaded the plugin header
        // constant yet. The verified snapshot is then the only authoritative
        // source of the runtime version; adopt it for all subsequent
        // projections from this registry instance.
        if ($this->runtimeVersion === 'unknown') $this->runtimeVersion = (string) $decoded['runtime_version'];
        if (!is_string($decoded['source_revision']) || preg_match('/^[a-f0-9]{40}$/i', $decoded['source_revision']) !== 1) self::invalidManifest('source_revision_invalid');
        $sourceRevision = self::readSourceRevision($root);
        if (self::hasGitMetadata($root) && $sourceRevision === null) self::invalidManifest('source_revision_unavailable');
        if ($sourceRevision !== null && !hash_equals($sourceRevision, strtolower($decoded['source_revision']))) self::invalidManifest('source_revision_mismatch', ['expected' => $sourceRevision, 'actual' => $decoded['source_revision']]);
        if (!is_string($decoded['generated_at']) || strtotime($decoded['generated_at']) === false) self::invalidManifest('generated_at_invalid');
        foreach (['entry_point' => 'docs/constitution/READ_FIRST.md', 'status_index' => 'docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md', 'execution_state' => 'docs/architecture/V3_EXECUTION_STATE.md'] as $field => $expected) {
            if ($decoded[$field] !== $expected) self::invalidManifest('canonical_path_mismatch', ['field' => $field, 'expected' => $expected, 'actual' => $decoded[$field]]);
        }
        if (!is_array($decoded['files']) || !array_is_list($decoded['files'])) self::invalidManifest('files_shape');
        $expectedPaths = self::documentPaths();
        usort($expectedPaths, 'strcmp');
        $actualPaths = [];
        $seenKeys = [];
        foreach ($decoded['files'] as $index => $entry) {
            if (!is_array($entry)) self::invalidManifest('file_entry_shape', ['index' => $index]);
            $entryFields = ['document_key', 'path', 'sha256', 'status', 'domain', 'classification'];
            $entryMissing = array_values(array_diff($entryFields, array_keys($entry)));
            if ($entryMissing !== []) self::invalidManifest('file_entry_field_missing', ['index' => $index, 'fields' => $entryMissing]);
            $entryUnexpected = array_values(array_diff(array_keys($entry), $entryFields));
            if ($entryUnexpected !== []) self::invalidManifest('file_entry_field_unexpected', ['index' => $index, 'fields' => $entryUnexpected]);
            $path = $entry['path'];
            if (!is_string($path) || $path === '' || $this->isTraversal($path)) self::invalidManifest('document_path_invalid', ['index' => $index]);
            $definition = self::definitionForPath($path);
            if ($definition === null) self::invalidManifest('document_path_not_allowlisted', ['path' => $path]);
            if (isset($seenKeys[$path])) self::invalidManifest('duplicate_document_path', ['path' => $path]);
            $key = self::keyForDefinitionPath($path);
            $declaredKey = $entry['document_key'];
            if (!is_string($declaredKey) || isset($seenKeys[$declaredKey])) self::invalidManifest('duplicate_document_key', ['document_key' => $declaredKey]);
            if ($key === null || $declaredKey !== $key) self::invalidManifest('document_key_mismatch', ['path' => $path, 'expected' => $key, 'actual' => $declaredKey]);
            $seenKeys[$path] = true;
            $seenKeys[$key] = true;
            if ($entry['status'] !== $definition['status'] || $entry['domain'] !== $definition['domain'] || $entry['classification'] !== $definition['classification']) self::invalidManifest('document_metadata_mismatch', ['document_key' => $key, 'path' => $path]);
            if (!is_string($entry['sha256']) || preg_match('/^[a-f0-9]{64}$/i', $entry['sha256']) !== 1) self::invalidManifest('document_hash_invalid', ['document_key' => $key]);
            $actualPaths[] = $path;
            $file = $this->resolveEntry($root, $path);
            if ($file === null) self::invalidManifest('document_file_missing_or_unsafe', ['document_key' => $key, 'path' => $path]);
            $actualHash = hash_file('sha256', $file) ?: '';
            if (!hash_equals($entry['sha256'], $actualHash)) self::invalidManifest('document_hash_mismatch', ['document_key' => $key, 'path' => $path, 'expected' => $entry['sha256'], 'actual' => $actualHash]);
        }
        sort($actualPaths);
        if ($actualPaths !== $expectedPaths) self::invalidManifest('document_inventory_mismatch', ['missing' => array_values(array_diff($expectedPaths, $actualPaths)), 'unexpected' => array_values(array_diff($actualPaths, $expectedPaths))]);
        if (!hash_equals($decoded['documentation_version'], hash('sha256', self::json($decoded['files'])))) self::invalidManifest('documentation_version_mismatch');
        if (!is_string($decoded['manifest_hash']) || preg_match('/^[a-f0-9]{64}$/i', $decoded['manifest_hash']) !== 1) self::invalidManifest('manifest_hash_invalid');
        if (!hash_equals($decoded['manifest_hash'], self::manifestHash($decoded))) self::invalidManifest('manifest_hash_mismatch');
        return $decoded;
    }

    /** @return array{path:string,classification:string,status:string,domain:string}|null */
    private static function definitionForPath(string $path): ?array
    {
        foreach (self::DOCUMENTS as $definition) if ($definition['path'] === $path) return $definition;
        return null;
    }

    private static function keyForDefinitionPath(string $path): ?string
    {
        foreach (self::DOCUMENTS as $key => $definition) if ($definition['path'] === $path) return $key;
        return null;
    }

    /** @param array<string,mixed> $details */
    private static function invalidManifest(string $diagnostic, array $details = []): never
    {
        throw new McpDocumentationException('DOC_MANIFEST_INVALID', null, ['diagnostic' => $diagnostic] + $details);
    }

    /** @param array<string,mixed> $manifest */
    private static function manifestHash(array $manifest): string { unset($manifest['manifest_hash'], $manifest['generated_at']); return hash('sha256', self::json($manifest)); }

    /** @param list<array<string,mixed>> $entries @return array<string,mixed> */
    private static function manifest(array $entries, string $runtimeVersion, string $generatedAt, ?string $sourceRevision = null): array
    {
        $manifest = ['schema_version' => self::MANIFEST_SCHEMA_VERSION, 'documentation_version' => hash('sha256', self::json($entries)), 'runtime_version' => $runtimeVersion, 'source_revision' => $sourceRevision, 'generated_at' => $generatedAt, 'entry_point' => 'docs/constitution/READ_FIRST.md', 'status_index' => 'docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md', 'execution_state' => 'docs/architecture/V3_EXECUTION_STATE.md', 'files' => $entries];
        $manifest['manifest_hash'] = self::manifestHash($manifest); return $manifest;
    }

    /** @param array{path:string,classification:string,status:string,domain:string} $definition @return array<string,mixed> */
    private static function entry(string $key, array $definition, string $path, string $content): array { return ['document_key' => $key, 'path' => $path, 'sha256' => hash('sha256', $content), 'status' => $definition['status'], 'domain' => $definition['domain'], 'classification' => $definition['classification']]; }

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

    private static function readSourceRevision(string $root): ?string
    {
        if (!self::hasGitMetadata($root)) return null;
        $revision = '';
        if (function_exists('proc_open')) {
            $pipes = [];
            $process = @proc_open('git -C ' . escapeshellarg($root) . ' rev-parse --verify HEAD 2>/dev/null', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($process)) {
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
                $revision = $exitCode === 0 && is_string($stdout) ? trim($stdout) : '';
            }
        }
        if ($revision === '' && function_exists('shell_exec')) {
            $fallback = shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --verify HEAD 2>/dev/null');
            $revision = is_string($fallback) ? trim($fallback) : '';
        }
        return preg_match('/^[0-9a-f]{40}$/i', $revision) === 1 ? $revision : null;
    }

    private static function hasGitMetadata(string $root): bool
    {
        return is_dir($root . DIRECTORY_SEPARATOR . '.git') || is_file($root . DIRECTORY_SEPARATOR . '.git');
    }

    /** The same deterministic runtime-package identity used by DEMO deploy. */
    private function buildIdentity(): string
    {
        $pluginRoot = dirname(__DIR__, 3);
        $files = [];
        if (!is_dir($pluginRoot)) return '';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pluginRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($pluginRoot) + 1));
            if (str_starts_with($relative, 'tests/') || preg_match('/(^|\/)(?:\.env|.*\.pem)$/i', $relative) === 1) continue;
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) return '';
            $files[$relative] = $hash;
        }
        ksort($files);
        return hash('sha256', self::json($files));
    }

    private static function generatedAt(): string { $epoch = getenv('SOURCE_DATE_EPOCH'); return is_string($epoch) && ctype_digit($epoch) ? gmdate('c', (int) $epoch) : gmdate('c'); }
    /** @param array<string,mixed> $value */
    private static function json(array $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
}

final class McpDocumentationException extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(public readonly string $reasonCode, ?string $message = null, public readonly array $details = []) { parent::__construct($message ?? $reasonCode); }
    /** @return array<string,mixed> */
    public function toArray(): array { return ['code' => $this->reasonCode, 'reason' => $this->reasonCode, 'details' => $this->details]; }
}
