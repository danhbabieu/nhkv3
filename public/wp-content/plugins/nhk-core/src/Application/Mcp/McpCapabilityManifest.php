<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

/**
 * Machine-readable projection of the catalog actually registered by NHK MCP.
 *
 * This is intentionally not a second operation registry: tool names and
 * governance come from McpToolCatalog, so a capability cannot be advertised
 * without a callable catalog entry.
 */
final class McpCapabilityManifest
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $tools = [];
        foreach (McpToolCatalog::tools() as $tool) $tools[$tool['name']] = $tool;

        $definitions = [
            'article' => ['owner' => 'wordpress', 'endpoint_types' => ['wp_post'], 'tools' => ['nhk.article.preflight', 'nhk.article.ingest'], 'seo_preflight' => true, 'relation_support' => true, 'media_support' => true, 'read_back' => true],
            'category' => ['owner' => 'wordpress_taxonomy', 'endpoint_types' => [], 'tools' => [], 'seo_preflight' => false, 'relation_support' => false, 'media_support' => false, 'read_back' => false],
            'authority' => ['owner' => 'authority', 'endpoint_types' => ['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'], 'tools' => ['nhk.entity.get', 'nhk.semantic.resolve'], 'seo_preflight' => false, 'relation_support' => true, 'media_support' => false, 'read_back' => true],
            'knowledge' => ['owner' => 'knowledge', 'endpoint_types' => ['knowledge'], 'tools' => ['nhk.knowledge.get', 'nhk.knowledge.ingest'], 'seo_preflight' => false, 'relation_support' => true, 'media_support' => false, 'read_back' => true],
            'source' => ['owner' => 'source_evidence', 'endpoint_types' => ['source'], 'tools' => ['nhk.source.get', 'nhk.source.ingest'], 'seo_preflight' => false, 'relation_support' => false, 'media_support' => false, 'read_back' => true],
            'evidence' => ['owner' => 'source_evidence', 'endpoint_types' => ['evidence'], 'tools' => ['nhk.evidence.get', 'nhk.evidence.ingest'], 'seo_preflight' => false, 'relation_support' => false, 'media_support' => false, 'read_back' => true],
            'graph' => ['owner' => 'graph', 'endpoint_types' => [], 'tools' => ['nhk.proposal.create', 'nhk.proposal.submit', 'nhk.proposal.approve', 'nhk.proposal.eligibility', 'nhk.proposal.apply'], 'seo_preflight' => false, 'relation_support' => true, 'media_support' => false, 'read_back' => true],
            'media' => ['owner' => 'media', 'endpoint_types' => ['media'], 'tools' => ['nhk.media.get', 'nhk.media.ingest', 'nhk.media.attachment.get'], 'seo_preflight' => true, 'relation_support' => true, 'media_support' => true, 'read_back' => true],
            'video' => ['owner' => 'video', 'endpoint_types' => ['video'], 'tools' => ['nhk.video.get', 'nhk.video.ingest'], 'seo_preflight' => true, 'relation_support' => true, 'media_support' => true, 'read_back' => true],
            'product' => ['owner' => 'authority', 'endpoint_types' => ['product'], 'tools' => ['nhk.entity.get'], 'seo_preflight' => false, 'relation_support' => true, 'media_support' => false, 'read_back' => true],
            'specimen' => ['owner' => 'authority', 'endpoint_types' => ['specimen'], 'tools' => ['nhk.entity.get'], 'seo_preflight' => false, 'relation_support' => true, 'media_support' => false, 'read_back' => true],
        ];

        $manifest = [];
        foreach ($definitions as $kind => $definition) {
            $reads = [];
            $writes = [];
            foreach ($definition['tools'] as $name) {
                $tool = $tools[$name] ?? null;
                if (!is_array($tool)) continue;
                if (($tool['kind'] ?? 'read') === 'mutation') $writes[] = $name;
                else $reads[] = $name;
            }
            $unsupported = array_values(array_diff($definition['tools'], array_merge($reads, $writes)));
            $manifest[$kind] = [
                'owner' => $definition['owner'],
                'endpoint_types' => $definition['endpoint_types'],
                'reads' => $reads,
                'writes' => $writes,
                'governed' => $writes !== [],
                'expected_revision' => $writes !== [],
                'idempotency' => in_array($kind, ['article', 'media', 'video', 'knowledge', 'source', 'evidence'], true),
                'relation_support' => $definition['relation_support'],
                'media_support' => $definition['media_support'],
                'seo_preflight' => $definition['seo_preflight'],
                'read_back' => $definition['read_back'],
                'unsupported' => $unsupported,
            ];
        }
        return $manifest;
    }

    /** @return array<string,mixed>|null */
    public static function forContentKind(string $contentKind): ?array
    {
        return self::all()[$contentKind] ?? null;
    }
}
