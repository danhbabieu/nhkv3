<?php
declare(strict_types=1);

namespace NHK\Core\Application\Home;

/** Presentation-only, deterministic hero selection. It never mutates Media. */
final class HomeHeroMediaSelector
{
    /** @param list<string> $manualIds @param list<array<string,mixed>> $candidates */
    public function select(array $manualIds, array $candidates, int $minimum = 3, int $maximum = 5): array
    {
        $minimum = max(1, $minimum);
        $maximum = max($minimum, min(5, $maximum));
        $byId = [];
        $seenUrls = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;
            $id = trim((string) ($candidate['_canonical_id'] ?? ''));
            $url = trim((string) ($candidate['image_url'] ?? ''));
            if ($id === '' || $url === '' || ($candidate['has_real_image'] ?? false) !== true || (int) ($candidate['width'] ?? 0) < 1 || (int) ($candidate['height'] ?? 0) < 1 || isset($byId[$id]) || isset($seenUrls[$url])) continue;
            $byId[$id] = $candidate;
            $seenUrls[$url] = true;
        }

        $selected = [];
        $selectedIds = [];
        foreach ($manualIds as $id) {
            $id = trim((string) $id);
            if ($id === '' || isset($selectedIds[$id]) || !isset($byId[$id])) continue;
            $selected[] = $byId[$id];
            $selectedIds[$id] = true;
            if (count($selected) >= $maximum) break;
        }

        $autoCandidates = array_values(array_filter($candidates, static function (mixed $candidate) use ($selectedIds, $byId): bool {
            return is_array($candidate)
                && isset($byId[(string) ($candidate['_canonical_id'] ?? '')])
                && !isset($selectedIds[(string) ($candidate['_canonical_id'] ?? '')]);
        }));
        usort($autoCandidates, static function (array $left, array $right): int {
            $leftArea = max(0, (int) ($left['width'] ?? 0)) * max(0, (int) ($left['height'] ?? 0));
            $rightArea = max(0, (int) ($right['width'] ?? 0)) * max(0, (int) ($right['height'] ?? 0));
            return $rightArea <=> $leftArea ?: strcmp((string) ($left['_canonical_id'] ?? ''), (string) ($right['_canonical_id'] ?? ''));
        });
        foreach ($autoCandidates as $candidate) {
            if (count($selected) >= $maximum || !is_array($candidate)) break;
            $id = trim((string) ($candidate['_canonical_id'] ?? ''));
            if ($id === '' || isset($selectedIds[$id]) || !isset($byId[$id])) continue;
            $selected[] = $byId[$id];
            $selectedIds[$id] = true;
        }

        return array_map(static function (array $item): array {
            unset($item['_canonical_id']);
            return $item;
        }, $selected);
    }
}
