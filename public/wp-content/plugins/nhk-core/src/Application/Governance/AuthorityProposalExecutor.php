<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Domain\Graph\GraphEdge;
use NHK\Core\Domain\Graph\NodeReference;

final class AuthorityProposalExecutor
{
    public function __construct(private AuthorityService $authority, private ?GraphService $graph = null) {}

    public function __invoke(Proposal $proposal): AuthorityEntity|GraphEdge
    {
        if (in_array($proposal->operation, ['relation_create', 'relation_retire', 'relation_reactivate'], true)) {
            return $this->relation($proposal);
        }
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

    private function relation(Proposal $proposal): mixed
    {
        if (!$this->graph) throw new \RuntimeException('Graph executor is not configured.');
        if ($proposal->operation === 'relation_create') {
            return $this->graph->create(
                new NodeReference((string) ($proposal->payload['source_type'] ?? ''), (string) ($proposal->payload['source_key'] ?? '')),
                (string) ($proposal->payload['predicate'] ?? ''),
                new NodeReference((string) ($proposal->payload['target_type'] ?? ''), (string) ($proposal->payload['target_key'] ?? '')),
            );
        }
        $edgeId = $proposal->targetUuid ?: $proposal->subjectId;
        return $proposal->operation === 'relation_retire'
            ? $this->graph->retire($edgeId, $proposal->expectedRevision)
            : $this->graph->reactivate($edgeId, $proposal->expectedRevision);
    }
}
