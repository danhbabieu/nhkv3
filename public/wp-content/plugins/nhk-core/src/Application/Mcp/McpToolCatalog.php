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
            self::tool('nhk.entity.get', 'Read one active Authority entity by type and UUID.', ['type' => ['type' => 'string', 'minLength' => 1], 'id' => self::uuidField()], ['type', 'id']),
            self::tool('nhk.media.get', 'Read one active Media identity and its public assets.', ['id' => self::uuidField()], ['id']),
            self::tool('nhk.media.ingest', 'Create a governed Media identity with complete asset and usage metadata; binary delivery remains separately verified.', [
                'stable_key' => ['type' => 'string', 'minLength' => 1],
                'name' => ['type' => 'string', 'minLength' => 1],
                'readiness' => ['type' => 'string', 'enum' => ['draft', 'ready', 'blocked']],
                'provenance' => ['type' => 'object'],
                'assets' => ['type' => 'array', 'items' => self::mediaAssetField()],
                'usages' => ['type' => 'array', 'items' => self::mediaUsageField()],
            ], ['stable_key', 'name'], true),
            self::tool('nhk.video.ingest', 'Create a governed canonical external Video reference from a validated YouTube URL.', [
                'url' => ['type' => 'string', 'minLength' => 1],
                'title' => ['type' => 'string'],
                'metadata' => ['type' => 'object'],
                'thumbnail_media_id' => self::uuidField(),
            ], ['url'], true),
            self::tool('nhk.video.get', 'Read one active canonical external Video reference.', ['id' => self::uuidField()], ['id']),
            self::tool('nhk.knowledge.get', 'Read one active Knowledge claim with public evidence.', ['id' => self::uuidField()], ['id']),
            self::tool('nhk.source.get', 'Read one active public Knowledge source with public evidence.', ['id' => self::uuidField()], ['id']),
            self::tool('nhk.evidence.get', 'Read one active public Knowledge evidence citation.', ['id' => self::uuidField()], ['id']),
            self::tool('nhk.knowledge.ingest', 'Create a governed atomic Knowledge claim with provenance.', [
                'stable_key' => ['type' => 'string', 'minLength' => 1],
                'text' => ['type' => 'string', 'minLength' => 1],
                'claim_type' => ['type' => 'string', 'enum' => ['fact', 'specification', 'history', 'technical', 'provenance', 'other']],
                'provenance' => ['type' => 'object'],
            ], ['stable_key', 'text'], true),
            self::tool('nhk.source.ingest', 'Create a governed Knowledge source with a durable locator and metadata.', [
                'stable_key' => ['type' => 'string', 'minLength' => 1],
                'title' => ['type' => 'string', 'minLength' => 1],
                'source_type' => ['type' => 'string', 'enum' => ['publication', 'website', 'archive', 'catalog', 'interview', 'other']],
                'locator' => ['type' => 'string'],
                'metadata' => ['type' => 'object'],
            ], ['stable_key', 'title'], true),
            self::tool('nhk.evidence.ingest', 'Create governed evidence linking an existing claim to an existing source.', [
                'claim_id' => self::uuidField(),
                'source_id' => self::uuidField(),
                'excerpt' => ['type' => 'string', 'minLength' => 1],
                'relation' => ['type' => 'string', 'enum' => ['supports', 'contradicts', 'qualifies']],
                'locator' => ['type' => 'string'],
                'metadata' => ['type' => 'object'],
            ], ['claim_id', 'source_id', 'excerpt'], true),
            self::tool('nhk.proposal.create', 'Create a governed semantic proposal.', [
                'operation' => ['type' => 'string'],
                'entity_type' => ['type' => 'string'],
                'subject_id' => ['type' => 'string'],
                'payload' => ['type' => 'object'],
                'expected_revision' => ['type' => 'integer', 'minimum' => 1],
                'target_uuid' => self::uuidField(),
                'dependency_ids' => ['type' => 'array', 'items' => self::uuidField()],
                'content_fingerprint' => ['type' => 'string'],
                'dependency_fingerprint' => ['type' => 'string'],
                'idempotency_key' => ['type' => 'string'],
            ], ['operation', 'payload'], true),
            self::tool('nhk.proposal.submit', 'Submit a governed proposal for review.', ['id' => self::uuidField()], ['id'], true),
            self::tool('nhk.proposal.approve', 'Approve a governed proposal with binding fingerprints.', ['id' => self::uuidField(), 'content_fingerprint' => ['type' => 'string'], 'dependency_fingerprint' => ['type' => 'string']], ['id', 'content_fingerprint', 'dependency_fingerprint'], true),
            self::tool('nhk.proposal.reject', 'Reject a governed proposal.', ['id' => self::uuidField()], ['id'], true),
            self::tool('nhk.proposal.eligibility', 'Check whether a proposal is eligible for controlled apply.', ['id' => self::uuidField()], ['id']),
            self::tool('nhk.proposal.apply', 'Apply an eligible proposal through the Governance boundary.', ['id' => self::uuidField()], ['id'], true),
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

    /** @return array{type:string,pattern:string} */
    private static function uuidField(): array
    {
        return ['type' => 'string', 'format' => 'uuid', 'pattern' => '^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-8][0-9A-Fa-f]{3}-[89ABab][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}$'];
    }

    private static function mediaAssetField(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['original', 'derivative']],
                'storage_key' => ['type' => 'string', 'minLength' => 1],
                'checksum' => ['type' => 'string', 'pattern' => '^[0-9A-Fa-f]{64}$'],
                'mime_type' => ['type' => 'string', 'minLength' => 1],
                'byte_size' => ['type' => 'integer', 'minimum' => 0],
                'width' => ['type' => 'integer', 'minimum' => 1],
                'height' => ['type' => 'integer', 'minimum' => 1],
                'visibility' => ['type' => 'string', 'enum' => ['PUBLIC', 'PRIVATE', 'HIDDEN']],
                'metadata' => ['type' => 'object'],
            ],
            'required' => ['kind', 'storage_key', 'checksum', 'mime_type', 'byte_size'],
            'additionalProperties' => false,
        ];
    }

    private static function mediaUsageField(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'endpoint_type' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,63}$'],
                'endpoint_key' => ['type' => 'string', 'minLength' => 1],
                'role' => ['type' => 'string', 'enum' => ['featured', 'inline', 'gallery', 'thumbnail', 'source']],
                'sort_order' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['endpoint_type', 'endpoint_key', 'role'],
            'additionalProperties' => false,
        ];
    }
}
