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
            self::tool('nhk.semantic.resolve', 'Resolve read-only Authority context by UUID, stable key or exact name/alias; ambiguous matches remain candidates.', ['context' => ['type' => 'object']], ['context']),
            self::tool('nhk.article.preflight', 'Read-only preflight for an existing WordPress Post semantic reconciliation.', self::articleProperties(false), ['intent']),
            self::tool('nhk.article.ingest', 'Resume a governed Article semantic reconciliation using the same idempotency key; Phase 1 is reconcile-only.', self::articleProperties(true), ['idempotency_key', 'intent'], true),
            self::tool('nhk.category.resolve', 'Resolve a native WordPress Category by ID, exact slug or exact name.', ['selector' => ['type' => 'object']], ['selector']),
            self::tool('nhk.category.create', 'Create or resolve one native WordPress Category idempotently.', ['name' => ['type' => 'string', 'minLength' => 1], 'slug' => ['type' => 'string'], 'parent' => ['type' => 'integer', 'minimum' => 0]], ['name'], true),
            self::tool('nhk.category.update', 'Update one native WordPress Category with optional state fingerprint CAS.', ['id' => ['type' => 'integer', 'minimum' => 1], 'changes' => ['type' => 'object'], 'expected_fingerprint' => ['type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$']], ['id', 'changes'], true),
            self::tool('nhk.category.assign', 'Assign a native Category to a WordPress Post.', ['post_id' => ['type' => 'integer', 'minimum' => 1], 'category_id' => ['type' => 'integer', 'minimum' => 1]], ['post_id', 'category_id'], true),
            self::tool('nhk.category.unassign', 'Remove a native Category from a WordPress Post.', ['post_id' => ['type' => 'integer', 'minimum' => 1], 'category_id' => ['type' => 'integer', 'minimum' => 1]], ['post_id', 'category_id'], true),
            self::tool('nhk.category.delete', 'Guardedly delete an unused native WordPress Category.', ['id' => ['type' => 'integer', 'minimum' => 1], 'allow_reassign' => ['type' => 'boolean']], ['id'], true),
            self::tool('nhk.article.draft.create', 'Create one native WordPress draft; never publishes and returns publication blockers.', ['idempotency_key' => ['type' => 'string', 'minLength' => 1], 'title' => ['type' => 'string'], 'content' => ['type' => 'string'], 'excerpt' => ['type' => 'string'], 'author' => ['type' => 'integer', 'minimum' => 0], 'research' => ['type' => 'object']], ['idempotency_key'], true),
            self::tool('nhk.article.draft.update', 'Optimistically update one eligible native WordPress draft with a state token.', ['post_id' => ['type' => 'integer', 'minimum' => 1], 'fields' => ['type' => 'object'], 'expected_state_token' => ['type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$']], ['post_id', 'fields', 'expected_state_token'], true),
            self::tool('nhk.article.publish', 'Publish one native WordPress Post only after the ArticlePublicationGate passes.', ['post_id' => ['type' => 'integer', 'minimum' => 1], 'expected_state_token' => ['type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$'], 'idempotency_key' => ['type' => 'string', 'minLength' => 1], 'evidence' => ['type' => 'object']], ['post_id', 'expected_state_token', 'idempotency_key', 'evidence'], true),
            self::tool('nhk.article.trash', 'Move one native WordPress Post to trash with state-token CAS.', ['post_id' => ['type' => 'integer', 'minimum' => 1], 'expected_state_token' => ['type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$'], 'idempotency_key' => ['type' => 'string', 'minLength' => 1]], ['post_id', 'expected_state_token', 'idempotency_key'], true),
            self::tool('nhk.article.restore', 'Restore one trashed native WordPress Post to draft with state-token CAS.', ['post_id' => ['type' => 'integer', 'minimum' => 1], 'expected_state_token' => ['type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$'], 'idempotency_key' => ['type' => 'string', 'minLength' => 1]], ['post_id', 'expected_state_token', 'idempotency_key'], true),
            self::tool('nhk.entity.get', 'Read one active Authority entity by type and UUID.', ['type' => ['type' => 'string', 'minLength' => 1], 'id' => self::uuidField()], ['type', 'id']),
            self::tool('nhk.media.get', 'Read one active Media identity and its public assets.', ['id' => self::uuidField()], ['id']),
            self::tool('nhk.media.ingest', 'Ingest governed Media metadata, or process one direct multipart image attachment into the WordPress Media Library without semantic inference.', [
                'stable_key' => ['type' => 'string', 'minLength' => 1, 'pattern' => '^[a-z0-9][a-z0-9._:-]{0,190}$'],
                'name' => ['type' => 'string', 'minLength' => 1],
                'readiness' => ['type' => 'string', 'enum' => ['draft', 'ready', 'blocked']],
                'provenance' => ['type' => 'object'],
                'assets' => ['type' => 'array', 'items' => self::mediaAssetField()],
                'usages' => ['type' => 'array', 'items' => self::mediaUsageField()],
                'file' => [
                    'type' => 'object',
                    'format' => 'binary',
                    'description' => 'Direct multipart file attachment. Send a file parameter; base64 and data URLs are not accepted.',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'size' => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'additionalProperties' => false,
                ],
                'filename' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'max_width' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2048],
                'max_height' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2048],
                'quality' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ], ['name'], true),
            self::tool('nhk.media.attachment.get', 'Read back one WordPress image attachment and its generated derivatives after file ingest.', ['attachment_id' => ['type' => 'integer', 'minimum' => 1]], ['attachment_id']),
            self::tool('nhk.video.ingest', 'Create a governed canonical external Video reference from a validated YouTube URL.', [
                'url' => ['type' => 'string', 'format' => 'uri', 'minLength' => 1],
                'title' => ['type' => 'string'],
                'metadata' => ['type' => 'object'],
                'thumbnail_media_id' => self::uuidField(true),
                'user_hint' => ['type' => 'string', 'maxLength' => 20000],
                'intended_category' => ['type' => 'string', 'enum' => ['01', '02', '03', '04', '05', '06', '07', '08']],
                'editorial_instruction' => ['type' => 'string', 'maxLength' => 20000],
                'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'intended_relations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'target_id' => self::uuidField(),
                            'target_type' => ['type' => 'string', 'minLength' => 1],
                            'predicate' => ['type' => 'string', 'enum' => ['about']],
                            'origin' => ['type' => 'string', 'enum' => ['EXPLICIT_USER_RELATION']],
                            'evidence_refs' => ['type' => 'array', 'items' => ['type' => 'object']],
                            'reason' => ['type' => 'string'],
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        ],
                        'required' => ['target_id', 'target_type'],
                        'additionalProperties' => false,
                    ],
                ],
            ], ['url'], true),
            self::tool('nhk.video.get', 'Read one active canonical external Video reference.', ['id' => self::uuidField()], ['id']),
            self::tool('nhk.knowledge.get', 'Read one active Knowledge claim with public evidence.', ['id' => self::uuidField()], ['id']),
            self::tool('nhk.source.get', 'Read one active public Knowledge source with public evidence.', ['id' => self::uuidField()], ['id']),
            self::tool('nhk.evidence.get', 'Read one active public Knowledge evidence citation.', ['id' => self::uuidField()], ['id']),
            self::tool('nhk.knowledge.ingest', 'Create a governed atomic Knowledge claim with provenance.', [
                'stable_key' => ['type' => 'string', 'minLength' => 1, 'pattern' => '^[a-z0-9][a-z0-9._:-]{0,190}$'],
                'text' => ['type' => 'string', 'minLength' => 1],
                'claim_type' => ['type' => 'string', 'enum' => ['fact', 'specification', 'history', 'technical', 'provenance', 'other']],
                'provenance' => ['type' => 'object'],
            ], ['stable_key', 'text'], true),
            self::tool('nhk.source.ingest', 'Create a governed Knowledge source with a durable locator and metadata.', [
                'stable_key' => ['type' => 'string', 'minLength' => 1, 'pattern' => '^[a-z0-9][a-z0-9._:-]{0,190}$'],
                'title' => ['type' => 'string', 'minLength' => 1],
                'source_type' => ['type' => 'string', 'enum' => ['publication', 'website', 'archive', 'catalog', 'interview', 'other']],
                'locator' => ['type' => 'string'],
                'visibility' => ['type' => 'string', 'enum' => ['PUBLIC', 'PRIVATE', 'HIDDEN']],
                'metadata' => ['type' => 'object'],
            ], ['stable_key', 'title'], true),
            self::tool('nhk.evidence.ingest', 'Create governed evidence linking an existing claim to an existing source.', [
                'claim_id' => self::uuidField(),
                'source_id' => self::uuidField(),
                'excerpt' => ['type' => 'string', 'minLength' => 1],
                'relation' => ['type' => 'string', 'enum' => ['supports', 'contradicts', 'qualifies']],
                'locator' => ['type' => 'string'],
                'visibility' => ['type' => 'string', 'enum' => ['PUBLIC', 'PRIVATE', 'HIDDEN']],
                'metadata' => ['type' => 'object'],
            ], ['claim_id', 'source_id', 'excerpt'], true),
            self::tool('nhk.proposal.create', 'Create a governed semantic proposal.', [
                'operation' => ['type' => 'string', 'enum' => self::governedOperations()],
                'entity_type' => ['type' => 'string'],
                'subject_id' => ['type' => 'string'],
                'payload' => ['type' => 'object'],
                'expected_revision' => ['type' => 'integer', 'minimum' => 1],
                'target_uuid' => self::uuidField(true),
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

    /** @return array{type:string|list<string>,format:string,pattern:string} */
    private static function uuidField(bool $nullable = false): array
    {
        return ['type' => $nullable ? ['string', 'null'] : 'string', 'format' => 'uuid', 'pattern' => '^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[1-8][0-9A-Fa-f]{3}-[89ABab][0-9A-Fa-f]{3}-[0-9A-Fa-f]{12}$'];
    }

    private static function mediaAssetField(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['original', 'derivative']],
                'storage_key' => ['type' => 'string', 'minLength' => 1],
                'original_filename' => ['type' => 'string'],
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
                'role' => ['type' => 'string', 'enum' => ['featured_primary', 'inline_primary', 'inline_supporting', 'featured', 'inline', 'gallery', 'thumbnail', 'source']],
                'sort_order' => ['type' => 'integer', 'minimum' => 0],
            ],
            'required' => ['endpoint_type', 'endpoint_key', 'role'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function articleProperties(bool $includeIdempotency): array
    {
        $properties = [
            'operation_id' => self::uuidField(true),
            'intent' => ['type' => 'string', 'enum' => ['reconcile', 'create', 'update']],
            'target_wp_post' => [
                'type' => 'object',
                'properties' => [
                    'endpoint_type' => ['type' => 'string', 'enum' => ['wp_post']],
                    'endpoint_key' => ['type' => 'string', 'pattern' => '^[1-9][0-9]*:[1-9][0-9]*$'],
                ],
                'required' => ['endpoint_type', 'endpoint_key'],
                'additionalProperties' => false,
            ],
            'expected_editorial_state' => [
                'type' => 'object',
                'properties' => ['state_token' => ['type' => 'string', 'pattern' => '^[a-fA-F0-9]{64}$']],
                'required' => ['state_token'],
                'additionalProperties' => false,
            ],
            'semantic_bundle' => [
                'type' => 'object',
                'properties' => ['commands' => ['type' => 'array', 'items' => self::articleCommandField()]],
                'required' => ['commands'],
                'additionalProperties' => false,
            ],
            'media_context' => [
                'type' => 'object',
                'properties' => [
                    'subject' => ['type' => 'string'],
                    'preferred_view' => ['type' => 'string'],
                    'keyword_groups' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'planned_title' => ['type' => 'string'],
                    'planned_filename_stem' => ['type' => 'string'],
                    'planned_alt_intent' => ['type' => 'string'],
                    'preferred_aspect' => ['type' => 'string'],
                    'minimum_width' => ['type' => 'integer', 'minimum' => 1],
                    'minimum_height' => ['type' => 'integer', 'minimum' => 1],
                    'focal_point_expected' => ['type' => 'boolean'],
                ],
                'additionalProperties' => false,
            ],
            'article_media' => [
                'type' => 'object',
                'properties' => [
                    'selected' => ['type' => 'object'],
                    'supporting_media_ids' => ['type' => 'array', 'items' => self::uuidField()],
                ],
                'additionalProperties' => false,
            ],
        ];
        $properties['research_topic'] = ['type' => 'string', 'minLength' => 1, 'maxLength' => 500];
        $properties['research_subject'] = ['type' => 'object'];
        if ($includeIdempotency) $properties['idempotency_key'] = ['type' => 'string', 'minLength' => 1, 'maxLength' => 191];
        return $properties;
    }

    /** @return array<string,mixed> */
    private static function articleCommandField(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slot' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'operation' => ['type' => 'string', 'enum' => self::governedOperations()],
                'entity_type' => ['type' => 'string', 'minLength' => 1],
                'subject_id' => ['type' => 'string', 'minLength' => 1],
                'target_uuid' => self::uuidField(true),
                'expected_revision' => ['type' => 'integer', 'minimum' => 1],
                'payload' => ['type' => 'object'],
                'dependency_slots' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]],
            ],
            'required' => ['slot', 'operation', 'entity_type', 'subject_id', 'expected_revision', 'payload'],
            'additionalProperties' => false,
        ];
    }

    /** @return list<string> */
    private static function governedOperations(): array
    {
        return ['create', 'ingest', 'relation_create', 'rekey', 'merge', 'rename', 'update', 'retire', 'reactivate', 'relation_retire', 'relation_reactivate'];
    }
}
