<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

/** Canonical public navigation data shared by desktop, mobile and hub projections. */
final class PublicNavigationDefinition
{
    /** @return array<string,mixed> */
    public static function groups(): array
    {
        return [
            'primary' => [
                ['label' => 'Thương hiệu', 'path' => '/thuong-hieu/'],
                ['label' => 'Nhóm đồng hồ', 'path' => '/loai-dong-ho/'],
                ['label' => 'Tri thức', 'path' => '/tri-thuc/'],
                ['label' => 'Video', 'path' => '/video/'],
            ],
            'discovery' => [
                ['label' => 'Mẫu', 'path' => '/mau/'],
                ['label' => 'Bộ máy', 'path' => '/bo-may/'],
                ['label' => 'Bản nhạc', 'path' => '/ban-nhac/'],
                ['label' => 'Linh kiện', 'path' => '/linh-kien/'],
                ['label' => 'Hiện vật', 'path' => '/hien-vat/'],
                ['label' => 'So sánh', 'path' => '/so-sanh/'],
                ['label' => 'Góc chia sẻ', 'path' => '/goc-chia-se/'],
            ],
            'footer' => [
                'Tra cứu' => [
                    ['label' => 'Thương hiệu', 'path' => '/thuong-hieu/'],
                    ['label' => 'Nhóm đồng hồ', 'path' => '/loai-dong-ho/'],
                    ['label' => 'Mẫu', 'path' => '/mau/'],
                    ['label' => 'Bộ máy', 'path' => '/bo-may/'],
                ],
                'Nội dung' => [
                    ['label' => 'Tri thức', 'path' => '/tri-thuc/'],
                    ['label' => 'Video', 'path' => '/video/'],
                    ['label' => 'Góc chia sẻ', 'path' => '/goc-chia-se/'],
                ],
                'Công cụ' => [
                    ['label' => 'So sánh', 'path' => '/so-sanh/'],
                    ['label' => 'Linh kiện', 'path' => '/linh-kien/'],
                    ['label' => 'Hiện vật', 'path' => '/hien-vat/'],
                ],
            ],
        ];
    }

    /** @return list<array{label:string,path:string}> */
    public static function items(): array
    {
        $items = [];
        foreach (self::groups() as $group => $entries) {
            if ($group === 'footer') continue;
            foreach ($entries as $entry) $items[$entry['path']] = $entry;
        }
        return array_values($items);
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
