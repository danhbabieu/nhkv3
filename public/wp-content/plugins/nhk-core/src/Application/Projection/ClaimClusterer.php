<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Domain\Projection\ProjectedClaim;

final class ClaimClusterer
{
    /** @param list<ProjectedClaim> $claims @return list<ClaimCluster> */
    public function cluster(array $claims): array
    {
        $groups = [];
        foreach ($claims as $claim) $groups[$this->key($claim)][] = $claim;
        $result = [];
        foreach ($groups as $key => $items) {
            usort($items, static fn (ProjectedClaim $a, ProjectedClaim $b): int => [$b->score, $a->claim->canonicalId] <=> [$a->score, $b->claim->canonicalId]);
            $representative = $items[0]; $supporting = []; $contradictory = [];
            foreach (array_slice($items, 1) as $item) {
                if ($item->status === 'DISPUTED') $contradictory[] = $item->claim->canonicalId;
                else $supporting[] = $item->claim->canonicalId;
            }
            $result[] = new ClaimCluster($key, $representative->claim->canonicalId, $supporting, $contradictory, $representative);
        }
        usort($result, static fn (ClaimCluster $a, ClaimCluster $b): int => $a->semanticKey <=> $b->semanticKey);
        return $result;
    }

    private function key(ProjectedClaim $claim): string
    {
        $text = $claim->claim->provenance['metadata']['semantic_key'] ?? '';
        if (!is_string($text) || trim($text) === '') $text = $claim->claim->claimText;
        $text = strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:thường|được|sử dụng|xuất hiện|ghi nhận|có thể|là|theo|cho biết)\b/u', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
