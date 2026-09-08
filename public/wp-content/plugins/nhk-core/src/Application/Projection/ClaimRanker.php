<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Domain\Projection\ProjectedClaim;

final class ClaimRanker
{
    /** @param list<ProjectedClaim> $claims @return list<ProjectedClaim> */
    public function rank(array $claims): array
    {
        $scored = array_map(fn (ProjectedClaim $claim): ProjectedClaim => $this->withScore($claim), $claims);
        usort($scored, static fn (ProjectedClaim $a, ProjectedClaim $b): int => [$b->score, $a->claim->canonicalId] <=> [$a->score, $b->claim->canonicalId]);
        return $scored;
    }

    public function score(ProjectedClaim $claim): float
    {
        $directness = $claim->scope->isDirect() ? 100.0 : 60.0;
        $distancePenalty = $claim->scope->graphDistance * 12.0;
        $status = match ($claim->status) { 'APPROVED' => 12.0, 'UNCERTAIN' => 2.0, 'DISPUTED' => -8.0, default => -100.0 };
        $evidence = min(20.0, ((int) ($claim->evidenceSummary['evidence_count'] ?? 0) * 4.0) + ((int) ($claim->evidenceSummary['source_count'] ?? 0) * 2.0));
        return $directness - $distancePenalty + $status + $evidence;
    }

    private function withScore(ProjectedClaim $claim): ProjectedClaim
    {
        return new ProjectedClaim($claim->claim, $claim->category, $claim->scope, $claim->status, $claim->context, $claim->displayText, $claim->evidenceSummary, $this->score($claim));
    }
}
