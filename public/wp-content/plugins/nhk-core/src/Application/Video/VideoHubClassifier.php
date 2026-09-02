<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class VideoHubClassifier
{
    /** @var array<string,string> */
    private const HUBS = [
        '01' => 'Bắt đầu tìm hiểu đồng hồ cổ', '02' => 'Thương hiệu & Lịch sử', '03' => 'Nhận diện bộ máy', '04' => 'So sánh đồng hồ cổ',
        '05' => 'Linh kiện & Cơ cấu', '06' => 'Âm thanh đồng hồ cổ', '07' => 'Kiểu dáng & Mặt số', '08' => 'Đồng hồ thực tế',
    ];

    /** @param array<string,mixed> $signals @return array<string,mixed> */
    public function classify(array $signals): array
    {
        $terms = [
            '01' => ['bắt đầu', 'nhập môn', 'tìm hiểu', 'hướng dẫn'], '02' => ['thương hiệu', 'lịch sử', 'nhà sản xuất', 'heritage'],
            '03' => ['bộ máy', 'movement', 'caliber', 'nhận diện máy'], '04' => ['so sánh', 'compare', 'khác nhau'],
            '05' => ['linh kiện', 'cơ cấu', 'bánh răng', 'cấu tạo'], '06' => ['âm thanh', 'chất âm', 'tiếng chuông', 'giai điệu'],
            '07' => ['kiểu dáng', 'mặt số', 'vỏ', 'dial'], '08' => ['thực tế', 'trên tay', 'review', 'trải nghiệm'],
        ];
        $scores = array_fill_keys(array_keys(self::HUBS), 0);
        $evidence = [];
        foreach (['source_title' => 3, 'source_description' => 2, 'user_hint' => 3, 'transcript' => 1, 'tags' => 1] as $field => $weight) {
            $value = $signals[$field] ?? '';
            $text = is_array($value) ? implode(' ', array_map('strval', $value)) : (string) $value;
            $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
            foreach ($terms as $key => $needles) foreach ($needles as $needle) if (str_contains($text, $needle)) { $scores[$key] += $weight; $evidence[] = ['signal' => $field, 'term' => $needle, 'hub' => $key]; }
        }
        arsort($scores);
        $primaryKey = (int) reset($scores) >= 2 ? (string) key($scores) : null;
        $categories = [];
        if ($primaryKey !== null) {
            $categories[] = ['key' => $primaryKey, 'label' => self::HUBS[$primaryKey], 'primary' => true, 'score' => $scores[$primaryKey]];
            foreach ($scores as $key => $score) if ($key !== $primaryKey && $score >= 4) $categories[] = ['key' => $key, 'label' => self::HUBS[$key], 'primary' => false, 'score' => $score];
        }
        return ['primary' => $primaryKey === null ? null : $categories[0], 'categories' => $categories, 'scores' => $scores, 'evidence' => array_values(array_unique($evidence, SORT_REGULAR)), 'warnings' => $primaryKey === null ? ['CATEGORY_UNRESOLVED'] : []];
    }

    /** @return array<string,string> */
    public static function hubs(): array { return self::HUBS; }
}
