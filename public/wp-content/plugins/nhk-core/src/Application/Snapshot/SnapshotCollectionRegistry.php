<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

final class SnapshotCollectionRegistry
{
    public const SCHEMA_VERSION = 'v3-semantic-snapshot/1';

    /** Logical repository collections; physical table names are adapter-owned. */
    public const COLLECTIONS = [
        'captures', 'capture_addenda', 'editorial_posts', 'editorial_post_meta', 'post_taxonomy',
        'authority_entities', 'knowledge', 'sources', 'claims', 'evidence',
        'proposals', 'proposal_approvals', 'proposal_audit_history', 'apply_attempts',
        'media', 'media_assets', 'media_usages', 'videos',
        'graph_nodes', 'graph_predicates', 'graph_edges', 'public_identities',
        'completion_state', 'idempotency_state',
    ];

    /** @return list<string> */
    public static function importOrder(): array
    {
        return [
            'authority_entities', 'captures', 'capture_addenda', 'editorial_posts', 'editorial_post_meta', 'post_taxonomy',
            'knowledge', 'sources', 'claims', 'evidence',
            'proposals', 'proposal_approvals', 'proposal_audit_history', 'apply_attempts',
            'media', 'media_assets', 'media_usages', 'videos',
            'graph_nodes', 'graph_predicates', 'graph_edges', 'public_identities',
            'completion_state', 'idempotency_state',
        ];
    }
}
