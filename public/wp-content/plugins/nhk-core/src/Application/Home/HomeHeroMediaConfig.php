<?php
declare(strict_types=1);

namespace NHK\Core\Application\Home;

use InvalidArgumentException;

/** Presentation-only configuration boundary for the homepage hero. */
final class HomeHeroMediaConfig
{
    public const OPTION = 'nhk_v3_home_hero_media_ids';
    public const MAX_MEDIA = 5;

    /**
     * @param list<mixed> $requestedIds
     * @param list<array<string,mixed>> $candidates
     * @return list<string>
     */
    public function validate(array $requestedIds, array $candidates): array
    {
        $available = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;
            $id = trim((string) ($candidate['id'] ?? $candidate['_canonical_id'] ?? ''));
            if ($id === '') continue;
            $available[$id] = $candidate;
        }

        $ids = [];
        foreach ($requestedIds as $rawId) {
            $id = trim((string) $rawId);
            if ($id === '' || isset($ids[$id])) continue;
            $ids[$id] = true;
        }
        if (count($ids) > self::MAX_MEDIA) {
            throw new InvalidArgumentException('Ảnh Hero được chọn tối đa 5 ảnh.');
        }

        $validated = [];
        foreach (array_keys($ids) as $id) {
            $candidate = $available[$id] ?? null;
            if (!is_array($candidate) || trim((string) ($candidate['image_url'] ?? '')) === '' || ($candidate['has_real_image'] ?? false) !== true) {
                throw new InvalidArgumentException('Không thể chọn Media không tồn tại, không khả dụng hoặc không phải ảnh công khai.');
            }
            $validated[] = $id;
        }

        return $validated;
    }

    /** @param list<string> $manualIds @return array{manual:int,auto_fallback:int,total:int} */
    public function status(array $manualIds, int $total = self::MAX_MEDIA): array
    {
        $total = max(1, min(self::MAX_MEDIA, $total));
        $manual = min($total, count(array_unique(array_filter(array_map('strval', $manualIds)))));
        return ['manual' => $manual, 'auto_fallback' => $total - $manual, 'total' => $total];
    }
}
