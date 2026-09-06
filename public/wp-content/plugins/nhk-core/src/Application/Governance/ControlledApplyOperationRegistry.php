<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

/** Read-only compatibility policy extracted from AuthorityProposalExecutor dispatch. */
final class ControlledApplyOperationRegistry implements OperationCompatibility
{
    /** @var array<string, list<string>> */
    private const ENTITY_OPERATIONS = [
        'knowledge' => ['create', 'ingest', 'update', 'retire', 'reactivate', 'relation_create', 'relation_retire', 'relation_reactivate'],
        'source' => ['create', 'ingest', 'update', 'retire', 'reactivate'],
        'evidence' => ['create', 'ingest', 'update', 'retire', 'reactivate'],
        'media' => ['ingest', 'relation_create', 'relation_retire', 'relation_reactivate'],
        'video' => ['ingest', 'update', 'retire', 'reactivate', 'relation_create', 'relation_retire', 'relation_reactivate'],
        'wp_post' => ['relation_create', 'relation_retire', 'relation_reactivate'],
        'relation' => ['relation_create', 'relation_retire', 'relation_reactivate'],
    ];

    /** @var list<string> */
    private const AUTHORITY_OPERATIONS = ['create', 'ingest', 'rekey', 'merge', 'rename', 'update', 'retire', 'reactivate'];

    public function supports(string $entityType, string $operation): bool
    {
        if (isset(self::ENTITY_OPERATIONS[$entityType])) return in_array($operation, self::ENTITY_OPERATIONS[$entityType], true);
        return in_array($operation, self::AUTHORITY_OPERATIONS, true);
    }
}
