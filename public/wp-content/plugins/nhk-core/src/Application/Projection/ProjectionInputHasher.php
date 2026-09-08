<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Domain\Projection\ProjectedClaim;

final class ProjectionInputHasher
{
    /** @param list<ProjectedClaim> $claims */
    public function hash(string $nodeUuid, array $claims, int $policyRevision = 1, int $templateRevision = 1): string
    {
        $claimSet = [];
        $edges = [];
        foreach ($claims as $claim) {
            $claimSet[] = [$claim->claim->canonicalId, $claim->claim->revision, $claim->scope->scope, $claim->scope->graphDistance, $claim->category, $claim->status];
            foreach ($claim->scope->graphPath as $hop) $edges[] = [(string) ($hop['edge_uuid'] ?? ''), (int) ($hop['edge_revision'] ?? 0), (string) ($hop['predicate'] ?? '')];
        }
        usort($claimSet, static fn (array $a, array $b): int => $a <=> $b);
        usort($edges, static fn (array $a, array $b): int => $a <=> $b);
        return hash('sha256', json_encode([$nodeUuid, $claimSet, $edges, $policyRevision, $templateRevision, 'semantic-claim-projection-v1'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
