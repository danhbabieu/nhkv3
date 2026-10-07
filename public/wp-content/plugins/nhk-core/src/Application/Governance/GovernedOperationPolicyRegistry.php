<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

/**
 * The one read-only source for generic Governance operation metadata.
 * Owner-specific semantic gates remain in their owner admission services.
 */
final class GovernedOperationPolicyRegistry implements OperationCompatibility
{
    /** @var array<string, GovernedOperationPolicy>|null */
    private static ?array $policies = null;

    public function find(string $entityType, string $operation): ?GovernedOperationPolicy
    {
        $key = strtolower(trim($entityType)) . ':' . strtolower(trim($operation));
        return $this->policies()[$key] ?? null;
    }

    /** @return list<GovernedOperationPolicy> */
    public function all(): array
    {
        return array_values($this->policies());
    }

    public function supports(string $entityType, string $operation): bool
    {
        return $this->find($entityType, $operation) !== null;
    }

    /** @return array<string, GovernedOperationPolicy> */
    private function policies(): array
    {
        if (self::$policies !== null) return self::$policies;

        $policies = [];
        $add = static function (string $entity, string $operation, string $family, string $lifecycle, string $revision, string $binding, bool $staging, array $capabilities = []) use (&$policies): void {
            $policy = new GovernedOperationPolicy($entity, $operation, $family, $lifecycle, $revision, $binding, $staging, true, $capabilities);
            $policies[$entity . ':' . $operation] = $policy;
        };

        foreach (['brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'specimen', 'product'] as $entity) {
            $add($entity, 'create', 'governed_authority_plan', 'CREATE', 'ZERO', 'NEW_AUTHORITY', true);
            $add($entity, 'ingest', 'governed_authority_plan', 'CREATE', 'ZERO', 'NEW_AUTHORITY', false);
            $add($entity, 'rekey', 'governed_authority_plan', 'MUTATE_EXISTING', 'CURRENT_REQUIRED', 'CANONICAL_UUID', true);
            $add($entity, 'merge', 'governed_authority_plan', 'SPECIAL', 'NONE', 'TWO_CANONICAL_UUIDS', false);
            $add($entity, 'rename', 'governed_authority_plan', 'MUTATE_EXISTING', 'CURRENT_REQUIRED', 'CANONICAL_UUID', true);
            $add($entity, 'update', 'governed_authority_plan', 'MUTATE_EXISTING', 'CURRENT_REQUIRED', 'CANONICAL_UUID', true);
            $add($entity, 'retire', 'governed_authority_plan', 'RETIRE', 'CURRENT_REQUIRED', 'CANONICAL_UUID', true);
            $add($entity, 'reactivate', 'governed_authority_plan', 'REACTIVATE', 'CURRENT_REQUIRED', 'CANONICAL_UUID', true);
        }

        foreach (['knowledge', 'source', 'evidence'] as $entity) {
            $family = $entity === 'knowledge' ? 'knowledge_delta' : 'source_evidence_reconciliation';
            foreach (['create', 'ingest'] as $operation) $add($entity, $operation, $family, 'CREATE', 'ZERO', 'NEW_CANONICAL', true);
            foreach (['update'] as $operation) $add($entity, $operation, $family, 'MUTATE_EXISTING', 'CURRENT_REQUIRED', 'CANONICAL_UUID', true);
            $add($entity, 'retire', $family, 'RETIRE', 'CURRENT_REQUIRED', 'CANONICAL_UUID', true);
            $add($entity, 'reactivate', $family, 'REACTIVATE', 'CURRENT_REQUIRED', 'CANONICAL_UUID', true);
        }
        $add('knowledge', 'collector_facet_update', 'knowledge_facet_update', 'SPECIAL', 'CURRENT_REQUIRED', 'CANONICAL_UUID', false);
        foreach (['relation_create', 'relation_retire', 'relation_reactivate'] as $operation) $add('knowledge', $operation, 'capture_child_relation', 'RELATION_MUTATION', 'NONE', 'RELATION_ENDPOINTS', false);

        $add('video', 'ingest', 'governed_video_plan', 'CREATE', 'ZERO', 'NEW_CANONICAL', true);
        $add('video', 'update', 'governed_video_plan', 'MUTATE_EXISTING', 'CURRENT_REQUIRED', 'CANONICAL_UUID', true);
        $add('video', 'source_refresh', 'video_source_refresh', 'SPECIAL', 'CURRENT_REQUIRED', 'CANONICAL_UUID', false, ['nhk_create_proposals']);
        $add('video', 'retire', 'governed_video_plan', 'RETIRE', 'CURRENT_REQUIRED', 'CANONICAL_UUID', false);
        $add('video', 'reactivate', 'governed_video_plan', 'REACTIVATE', 'CURRENT_REQUIRED', 'CANONICAL_UUID', false);
        foreach (['relation_create', 'relation_retire', 'relation_reactivate'] as $operation) $add('video', $operation, 'capture_child_relation', 'RELATION_MUTATION', 'NONE', 'RELATION_ENDPOINTS', false);

        $add('media', 'ingest', 'media_ingest', 'CREATE', 'ZERO', 'NEW_CANONICAL', false);
        $add('media', 'update', 'media_metadata_reconciliation', 'MUTATE_EXISTING', 'CURRENT_REQUIRED', 'CANONICAL_UUID', true);
        foreach (['add', 'replace', 'remove', 'representative_bind'] as $operation) $add('media', $operation, 'media_usage_reconciliation', 'SPECIAL', 'NONE', 'MEDIA_USAGE', true, ['nhk_internal_content_operations']);
        foreach (['relation_create', 'relation_retire', 'relation_reactivate'] as $operation) $add('media', $operation, 'capture_child_relation', 'RELATION_MUTATION', 'NONE', 'RELATION_ENDPOINTS', false);

        $add('wp_post', 'relation_create', 'capture_child_relation', 'RELATION_MUTATION', 'NONE', 'WP_POST_ENDPOINT', false);
        $add('wp_post', 'relation_retire', 'capture_child_relation', 'RELATION_MUTATION', 'NONE', 'WP_POST_ENDPOINT', false);
        $add('wp_post', 'relation_reactivate', 'capture_child_relation', 'RELATION_MUTATION', 'NONE', 'WP_POST_ENDPOINT', false);
        $add('wp_post', 'subject_bind', 'wp_post_subject_binding', 'SPECIAL', 'NONE', 'WP_POST_ENDPOINT', false, ['nhk_internal_content_operations']);

        foreach (['relation_create', 'relation_retire', 'relation_reactivate', 'relation_replace'] as $operation) $add('relation', $operation, 'capture_child_relation', 'RELATION_MUTATION', 'NONE', 'RELATION_ENDPOINTS', true);

        return self::$policies = $policies;
    }
}
