<?php
declare(strict_types=1);

namespace NHK\Core\Application\FacebookAudit;

use NHK\Core\Domain\FacebookAudit\FacebookValue;

final class FacebookAuditWorkbook
{
    /** @var list<string> */
    public const SHEETS = ['Tổng quan', 'Danh sách bài Fanpage', 'Danh sách bài hội nhóm', 'Bài tương tác thấp', 'Bài có rủi ro nhãn hiệu', 'Bài trùng lặp', 'Đề xuất xóa', 'Thiếu quyền truy cập', 'Chờ phê duyệt'];

    /** @param array<string,mixed> $overview @param list<array<string,mixed>> $pagePosts @param list<array<string,mixed>> $groupPosts @param list<array<string,mixed>> $classifiedRows @param array<string,string> $access @return array<string,array{headers:list<string>,rows:list<list<mixed>>}> */
    public static function compose(array $overview, array $pagePosts, array $groupPosts, array $classifiedRows, array $access): array
    {
        $safeOverview = [];
        foreach ($overview as $key => $value) if (!preg_match('/token|secret|password|authorization|cookie/i', (string) $key)) $safeOverview[(string) $key] = self::display($value);
        $classifiedById = [];
        foreach ($classifiedRows as $row) $classifiedById[(string) ($row['post_id'] ?? '')] = $row;
        $pageRows = self::mergeClassification($pagePosts, $classifiedById);
        $groupRows = self::mergeClassification($groupPosts, $classifiedById);
        $allRows = array_merge($pageRows, $groupRows);
        $postHeaders = ['post_id', 'post_url', 'published_at', 'content_type', 'text', 'media_references', 'reaction_count', 'comment_count', 'share_count', 'video_views', 'source', 'classification', 'reason_codes'];
        $groupHeaders = [...$postHeaders, 'group_id', 'group_url', 'group_post_kind', 'page_author_id', 'page_author_verified'];
        $low = array_values(array_filter($allRows, static fn (array $row): bool => ($row['classification'] ?? null) === 'LOW_ENGAGEMENT'));
        $trademark = array_values(array_filter($allRows, static fn (array $row): bool => ($row['classification'] ?? null) === 'TRADEMARK_REVIEW'));
        $duplicates = array_values(array_filter($allRows, static fn (array $row): bool => ($row['classification'] ?? null) === 'DUPLICATE'));
        $delete = array_values(array_filter($allRows, static fn (array $row): bool => ($row['classification'] ?? null) === 'DELETE_CANDIDATE'));
        $approval = array_values(array_filter($allRows, static fn (array $row): bool => in_array(($row['classification'] ?? null), ['LOW_ENGAGEMENT', 'DUPLICATE', 'TRADEMARK_REVIEW', 'DELETE_CANDIDATE', 'INSUFFICIENT_DATA'], true)));
        $accessRows = [];
        foreach ($access as $capability => $status) if ($status !== 'GRANTED') $accessRows[] = [(string) $capability, (string) $status];

        return [
            'Tổng quan' => ['headers' => ['key', 'value'], 'rows' => array_map(static fn (array $item): array => [$item[0], $item[1]], array_map(null, array_keys($safeOverview), array_values($safeOverview)))],
            'Danh sách bài Fanpage' => ['headers' => $postHeaders, 'rows' => self::rows($pageRows, $postHeaders)],
            'Danh sách bài hội nhóm' => ['headers' => $groupHeaders, 'rows' => self::rows($groupRows, $groupHeaders)],
            'Bài tương tác thấp' => ['headers' => $postHeaders, 'rows' => self::rows($low, $postHeaders)],
            'Bài có rủi ro nhãn hiệu' => ['headers' => $postHeaders, 'rows' => self::rows($trademark, $postHeaders)],
            'Bài trùng lặp' => ['headers' => $postHeaders, 'rows' => self::rows($duplicates, $postHeaders)],
            'Đề xuất xóa' => ['headers' => $postHeaders, 'rows' => self::rows($delete, $postHeaders)],
            'Thiếu quyền truy cập' => ['headers' => ['capability', 'status'], 'rows' => $accessRows],
            'Chờ phê duyệt' => ['headers' => $postHeaders, 'rows' => self::rows($approval, $postHeaders)],
        ];
    }

    /** @param list<array<string,mixed>> $rows @param array<string,array<string,mixed>> $classifiedById @return list<array<string,mixed>> */
    private static function mergeClassification(array $rows, array $classifiedById): array
    {
        return array_map(static fn (array $row): array => array_merge($row, $classifiedById[(string) ($row['post_id'] ?? '')] ?? []), $rows);
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $headers @return list<list<mixed>> */
    private static function rows(array $rows, array $headers): array
    {
        return array_map(static function (array $row) use ($headers): array {
            return array_map(static function (string $header) use ($row): mixed {
                $value = $row[$header] ?? null;
                if ($header === 'post_url' || $header === 'group_url') return ['value' => self::display($value), 'url' => self::display($value)];
                return self::display($value);
            }, $headers);
        }, $rows);
    }

    private static function display(mixed $value): mixed
    {
        if ($value instanceof FacebookValue) return $value->state === FacebookValue::KNOWN ? self::display($value->value) : $value->state;
        if ($value === null) return 'NULL';
        if (is_bool($value)) return $value ? 'YES' : 'NO';
        if (is_array($value)) return implode(', ', array_map(static fn (mixed $item): string => (string) self::display($item), $value));
        return $value;
    }
}
