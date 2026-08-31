<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Authority;

use NHK\Core\Contracts\Authority\AuthorityAuditSink;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Infrastructure\Governance\WpdbAuditSink as EventStore;

final class WpdbAuditSink implements AuthorityAuditSink
{
    public function __construct(private ?EventStore $events = null) {}
    public function record(string $event, AuthorityEntity $entity): void
    {
        ($this->events ?? new EventStore())->recordEvent('AuthorityEntity'.ucfirst($event), 'authority', $entity->canonicalId, null, ['entity_type' => $entity->entityType, 'stable_key' => $entity->stableKey, 'revision' => $entity->revision, 'state' => $entity->state->value]);
    }
}
