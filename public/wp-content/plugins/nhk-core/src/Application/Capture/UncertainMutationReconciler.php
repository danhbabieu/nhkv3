<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

final class UncertainMutationReconciler
{
    public function reconcile(array $identity, callable $captureLookup, callable $videoLookup, callable $ownerLookup): array
    {
        $capture = $captureLookup($identity);
        $video = $videoLookup($identity);
        $owner = $ownerLookup($identity);
        $owners = array_values(array_filter([$video, $owner], static fn (mixed $item): bool => is_array($item) && $item !== []));
        if (count($owners) > 1 && !$this->sameOwner($owners)) {
            return ['status' => 'CONFLICT_FAIL_CLOSED', 'identity' => $identity, 'capture' => $capture, 'owners' => $owners];
        }
        if ($capture !== null || $owners !== []) {
            return ['status' => 'REUSE_AND_RESUME', 'identity' => $identity, 'capture' => $capture, 'video' => $video ?: $owner];
        }
        if ($identity === []) return ['status' => 'NO_CANONICAL_OUTCOME', 'identity' => []];
        return ['status' => 'REPLAY_SAME_IDENTITY', 'identity' => $identity];
    }

    private function sameOwner(array $owners): bool
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $owner): string => trim((string) ($owner['canonical_id'] ?? $owner['owner_id'] ?? '')), $owners))));
        return count($ids) <= 1;
    }
}
