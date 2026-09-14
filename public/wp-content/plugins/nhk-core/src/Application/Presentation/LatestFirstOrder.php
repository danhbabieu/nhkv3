<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

/** Deterministic newest-first ordering for public archives and feeds. */
final class LatestFirstOrder
{
    /** @param list<mixed> $items @return list<mixed> */
    public static function sort(array $items, callable $publishedAt, callable $createdAt, callable $tieBreaker, ?callable $pinned = null, ?callable $updatedAt = null, string $mode = 'default'): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['default', 'updated'], true)) throw new \InvalidArgumentException('Unknown newest-first ordering mode.');
        $indexed = [];
        foreach ($items as $index => $item) {
            $published = self::timestamp($publishedAt($item));
            $created = self::timestamp($createdAt($item));
            $updated = $updatedAt === null ? null : self::timestamp($updatedAt($item));
            $indexed[] = ['item' => $item, 'index' => $index, 'pinned' => $pinned === null ? 0 : ((bool) $pinned($item) ? 1 : 0), 'has_published' => $published === null ? 0 : 1, 'published' => $published ?? PHP_INT_MIN, 'has_updated' => $updated === null ? 0 : 1, 'updated' => $updated ?? PHP_INT_MIN, 'created' => $created ?? PHP_INT_MIN, 'tie' => (string) $tieBreaker($item), 'mode' => $mode];
        }
        $hasChronology = $mode === 'updated'
            ? static fn (array $entry): bool => $entry['has_updated'] === 1 || $entry['created'] !== PHP_INT_MIN
            : static fn (array $entry): bool => $entry['has_published'] === 1 || $entry['created'] !== PHP_INT_MIN;
        if ($indexed !== [] && !array_filter($indexed, $hasChronology)) return $items;
        usort($indexed, static function (array $left, array $right): int {
            $keys = $left['mode'] === 'updated' ? ['pinned', 'has_updated', 'updated', 'created'] : ['pinned', 'has_published', 'published', 'created'];
            foreach ($keys as $key) if ($left[$key] !== $right[$key]) return $right[$key] <=> $left[$key];
            $tie = strcmp((string) $right['tie'], (string) $left['tie']);
            return $tie !== 0 ? $tie : ((int) $left['index'] <=> (int) $right['index']);
        });
        return array_values(array_map(static fn (array $entry): mixed => $entry['item'], $indexed));
    }

    private static function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) return $value->getTimestamp();
        if (!is_string($value) || trim($value) === '') return null;
        $timestamp = strtotime($value);
        return $timestamp === false ? null : $timestamp;
    }
}
