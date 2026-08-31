<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

final class McpToolCatalog
{
    /** @return list<array{name:string,description:string,inputSchema:array,kind:string,governed:bool}> */
    public static function tools(): array
    {
        return [
            self::tool('nhk.search', 'Search native editorial posts and active semantic records with bounded pagination.', ['q' => ['type' => 'string'], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]], ['q']),
            self::tool('nhk.entity.get', 'Read one active Authority entity by type and UUID.', ['type' => ['type' => 'string'], 'id' => ['type' => 'string']], ['type', 'id']),
            self::tool('nhk.media.get', 'Read one active Media identity and its public assets.', ['id' => ['type' => 'string']], ['id']),
            self::tool('nhk.media.ingest', 'Create a governed Media identity with complete asset and usage metadata; binary delivery remains separately verified.', [
                'stable_key' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'readiness' => ['type' => 'string'],
                'provenance' => ['type' => 'object'],
                'assets' => ['type' => 'array', 'items' => ['type' => 'object']],
                'usages' => ['type' => 'array', 'items' => ['type' => 'object']],
            ], ['stable_key', 'name'], true),
            self::tool('nhk.video.ingest', 'Create a governed canonical external Video reference from a validated YouTube URL.', [
                'url' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'metadata' => ['type' => 'object'],
                'thumbnail_media_id' => ['type' => 'string'],
            ], ['url'], true),
            self::tool('nhk.video.get', 'Read one active canonical external Video reference.', ['id' => ['type' => 'string']], ['id']),
            self::tool('nhk.knowledge.get', 'Read one active Knowledge claim with public evidence.', ['id' => ['type' => 'string']], ['id']),
            self::tool('nhk.source.get', 'Read one active public Knowledge source with public evidence.', ['id' => ['type' => 'string']], ['id']),
            self::tool('nhk.evidence.get', 'Read one active public Knowledge evidence citation.', ['id' => ['type' => 'string']], ['id']),
            self::tool('nhk.knowledge.ingest', 'Create a governed atomic Knowledge claim with provenance.', [
                'stable_key' => ['type' => 'string'],
                'text' => ['type' => 'string'],
                'claim_type' => ['type' => 'string'],
                'provenance' => ['type' => 'object'],
            ], ['stable_key', 'text'], true),
            self::tool('nhk.source.ingest', 'Create a governed Knowledge source with a durable locator and metadata.', [
                'stable_key' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'source_type' => ['type' => 'string'],
                'locator' => ['type' => 'string'],
                'metadata' => ['type' => 'object'],
            ], ['stable_key', 'title'], true),
            self::tool('nhk.evidence.ingest', 'Create governed evidence linking an existing claim to an existing source.', [
                'claim_id' => ['type' => 'string'],
                'source_id' => ['type' => 'string'],
                'excerpt' => ['type' => 'string'],
                'relation' => ['type' => 'string'],
                'locator' => ['type' => 'string'],
                'metadata' => ['type' => 'object'],
            ], ['claim_id', 'source_id', 'excerpt'], true),
            self::tool('nhk.proposal.create', 'Create a governed semantic proposal.', ['operation' => ['type' => 'string'], 'entity_type' => ['type' => 'string'], 'payload' => ['type' => 'object']], ['operation', 'payload'], true),
            self::tool('nhk.proposal.submit', 'Submit a governed proposal for review.', ['id' => ['type' => 'string']], ['id'], true),
            self::tool('nhk.proposal.approve', 'Approve a governed proposal with binding fingerprints.', ['id' => ['type' => 'string'], 'content_fingerprint' => ['type' => 'string'], 'dependency_fingerprint' => ['type' => 'string']], ['id', 'content_fingerprint', 'dependency_fingerprint'], true),
            self::tool('nhk.proposal.reject', 'Reject a governed proposal.', ['id' => ['type' => 'string']], ['id'], true),
            self::tool('nhk.proposal.eligibility', 'Check whether a proposal is eligible for controlled apply.', ['id' => ['type' => 'string']], ['id']),
            self::tool('nhk.proposal.apply', 'Apply an eligible proposal through the Governance boundary.', ['id' => ['type' => 'string']], ['id'], true),
        ];
    }

    public static function isGoverned(string $tool): bool
    {
        foreach (self::tools() as $definition) if ($definition['name'] === $tool) return $definition['governed'];
        return false;
    }

    private static function tool(string $name, string $description, array $properties, array $required, bool $governed = false): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false],
            'kind' => $governed ? 'mutation' : 'read',
            'governed' => $governed,
        ];
    }
}
