<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

/** Public presentation state; it does not represent semantic lifecycle. */
final class SectionStatus
{
    /** @param list<array<string,mixed>> $items @return array{status:string,items:list<array<string,mixed>>,count:int} */
    public static function forItems(array $items, string $ownerStatus = 'AVAILABLE'): array
    {
        $ownerStatus = strtoupper(trim($ownerStatus));
        if (in_array($ownerStatus, ['BLOCKED', 'UNAVAILABLE'], true)) {
            return ['status' => $ownerStatus, 'items' => [], 'count' => 0];
        }

        $items = array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)));
        return ['status' => $items === [] ? 'EMPTY' : 'AVAILABLE', 'items' => $items, 'count' => count($items)];
    }
}
