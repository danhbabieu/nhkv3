<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Governance\Proposal;

final class AuthorityProposalExecutor
{
    public function __construct(private AuthorityService $authority) {}

    public function __invoke(Proposal $proposal): AuthorityEntity
    {
        $payload = $proposal->payload;
        $target = $proposal->targetUuid ?: $proposal->subjectId;
        return match ($proposal->operation) {
            'create', 'ingest' => $this->authority->create(
                $proposal->entityType,
                (string) ($payload['stable_key'] ?? ''),
                (string) ($payload['name'] ?? ''),
                is_array($payload['entity_payload'] ?? null) ? $payload['entity_payload'] : [],
            ),
            'rename' => $this->authority->rename($target, (string) ($payload['name'] ?? ''), $proposal->expectedRevision),
            'update' => $this->authority->update($target, is_array($payload['entity_payload'] ?? null) ? $payload['entity_payload'] : [], $proposal->expectedRevision),
            'retire' => $this->authority->retire($target, $proposal->expectedRevision),
            'reactivate' => $this->authority->reactivate($target, $proposal->expectedRevision),
            default => throw new \InvalidArgumentException('Unsupported authority proposal operation: ' . $proposal->operation),
        };
    }
}
