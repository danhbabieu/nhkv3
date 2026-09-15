<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

/** Canonical public navigation data shared by desktop, mobile and hub projections. */
final class PublicNavigationDefinition
{
    /** @return list<array{label:string,path:string}> */
    public static function items(): array
    {
        return [
            ['label' => 'Tri thức', 'path' => '/tri-thuc/'],
            ['label' => 'Thương hiệu', 'path' => '/thuong-hieu/'],
            ['label' => 'Nhóm đồng hồ', 'path' => '/loai-dong-ho/'],
            ['label' => 'Mẫu', 'path' => '/mau/'],
            ['label' => 'Bộ máy', 'path' => '/bo-may/'],
            ['label' => 'Bản nhạc', 'path' => '/ban-nhac/'],
            ['label' => 'So sánh', 'path' => '/so-sanh/'],
            ['label' => 'Linh kiện', 'path' => '/linh-kien/'],
            ['label' => 'Hiện vật', 'path' => '/hien-vat/'],
            ['label' => 'Video', 'path' => '/video/'],
            ['label' => 'Góc chia sẻ', 'path' => '/goc-chia-se/'],
        ];
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    public static function sortHubItems(array $items): array
    {
        $priority = ['brand' => 10, 'clock_type' => 20, 'model' => 30, 'variant' => 40, 'movement' => 50, 'music' => 60, 'component' => 70, 'specimen' => 80, 'product' => 90];
        $indexed = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) continue;
            $indexed[] = ['item' => $item, 'index' => $index, 'priority' => $priority[(string) ($item['type'] ?? '')] ?? 999];
        }
        usort($indexed, static fn (array $left, array $right): int => [$left['priority'], $left['index']] <=> [$right['priority'], $right['index']]);
        return array_values(array_map(static fn (array $entry): array => $entry['item'], $indexed));
    }
}
