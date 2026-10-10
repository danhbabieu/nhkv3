<?php
declare(strict_types=1);

namespace NHK\Core\Application\FacebookAudit;

use DateTimeImmutable;
use NHK\Core\Domain\FacebookAudit\FacebookValue;

final class FacebookAuditClassifier
{
    public const CLASSIFICATIONS = ['KEEP', 'LOW_ENGAGEMENT', 'DUPLICATE', 'TRADEMARK_REVIEW', 'DELETE_CANDIDATE', 'INSUFFICIENT_DATA'];

    public function __construct(private FacebookDuplicateDetector $duplicates, private DateTimeImmutable $now = new DateTimeImmutable('now')) {}

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $options @return array{rows:list<array<string,mixed>>,counts:array<string,int>,duplicate_groups:list<array<string,mixed>>} */
    public function classify(array $rows, array $options = []): array
    {
        $duplicateGroups = $this->duplicates->groups($rows);
        $duplicateByPost = [];
        foreach ($duplicateGroups as $groupIndex => $group) foreach ($group['post_ids'] as $postId) $duplicateByPost[$postId] = 'duplicate-' . ($groupIndex + 1);

        $prepared = [];
        $buckets = [];
        foreach ($rows as $row) {
            $age = $this->age($row['published_at'] ?? null);
            $total = $this->engagementTotal($row);
            $bucket = $age['bucket'];
            $key = strtoupper((string) ($row['content_type'] ?? 'UNKNOWN')) . '|' . ($bucket ?? 'UNKNOWN');
            if ($total !== null && $bucket !== null) $buckets[$key][] = $total;
            $prepared[] = [$row, $age, $total, $key];
        }
        foreach ($buckets as &$values) { sort($values, SORT_NUMERIC); } unset($values);

        $lexicon = array_values(array_filter(array_map(static fn (mixed $term): string => trim((string) $term), (array) ($options['review_lexicon'] ?? [])), static fn (string $term): bool => $term !== ''));
        $deleteMinAge = max(1, (int) ($options['delete_min_age_days'] ?? 365));
        $resultRows = [];
        $counts = array_fill_keys(self::CLASSIFICATIONS, 0);
        foreach ($prepared as [$row, $age, $total, $key]) {
            $id = trim((string) ($row['post_id'] ?? ''));
            $reasons = [];
            $insufficient = $id === '' || $age['bucket'] === null || $total === null;
            if ($id === '') $reasons[] = 'POST_ID_UNAVAILABLE';
            if ($age['bucket'] === null) $reasons[] = 'PUBLISHED_AT_UNAVAILABLE';
            if ($total === null) $reasons[] = 'ENGAGEMENT_UNAVAILABLE';
            $low = false;
            if (!$insufficient && count($buckets[$key] ?? []) >= 2) {
                $values = $buckets[$key];
                $threshold = $values[max(0, (int) ceil(count($values) * 0.25) - 1)];
                $low = $total <= $threshold;
                if ($low) $reasons[] = 'LOW_ENGAGEMENT_BOTTOM_QUARTILE';
            }
            $duplicateGroup = $duplicateByPost[$id] ?? null;
            if ($duplicateGroup !== null) $reasons[] = 'DUPLICATE_CONTENT_FINGERPRINT';
            $trademark = $this->matchesLexicon((string) ($row['text'] ?? ''), $lexicon);
            if ($trademark) $reasons[] = 'TRADEMARK_REVIEW_REQUIRED';
            $delete = !$insufficient && $low && ($age['days'] ?? 0) >= $deleteMinAge && ($row['commercial_signal'] ?? false) !== true && ($row['customer_interest_signal'] ?? false) !== true && $duplicateGroup === null && !$trademark;
            if ($delete) $reasons[] = 'STALE_LOW_ENGAGEMENT_NO_COMMERCIAL_SIGNAL';
            $classification = $insufficient ? 'INSUFFICIENT_DATA' : ($duplicateGroup !== null ? 'DUPLICATE' : ($trademark ? 'TRADEMARK_REVIEW' : ($delete ? 'DELETE_CANDIDATE' : ($low ? 'LOW_ENGAGEMENT' : 'KEEP'))));
            $counts[$classification]++;
            $row['classification'] = $classification;
            $row['reason_codes'] = array_values(array_unique($reasons));
            $row['age_days'] = $age['days'];
            $row['age_bucket'] = $age['bucket'];
            $row['engagement_total'] = $total;
            $row['duplicate_group'] = $duplicateGroup;
            $row['delete_candidate'] = $delete;
            $resultRows[] = $row;
        }
        return ['rows' => $resultRows, 'counts' => $counts, 'duplicate_groups' => $duplicateGroups];
    }

    /** @param array<string,mixed> $row */
    private function engagementTotal(array $row): ?int
    {
        $total = 0; $known = false;
        foreach (['reaction_count', 'comment_count', 'share_count'] as $key) {
            $value = $row[$key] ?? FacebookValue::unavailable();
            if (!$value instanceof FacebookValue || $value->state !== FacebookValue::KNOWN || !is_numeric($value->value)) continue;
            $known = true; $total += (int) $value->value;
        }
        return $known ? $total : null;
    }

    /** @return array{days:?int,bucket:?string} */
    private function age(mixed $publishedAt): array
    {
        $publishedAt = trim((string) ($publishedAt ?? ''));
        if ($publishedAt === '') return ['days' => null, 'bucket' => null];
        try { $date = new DateTimeImmutable($publishedAt); } catch (\Throwable) { return ['days' => null, 'bucket' => null]; }
        $days = max(0, (int) $date->diff($this->now)->format('%r%a'));
        return ['days' => $days, 'bucket' => $days <= 30 ? '0_30' : ($days <= 90 ? '31_90' : ($days <= 365 ? '91_365' : '365_PLUS'))];
    }

    /** @param list<string> $lexicon */
    private function matchesLexicon(string $text, array $lexicon): bool
    {
        foreach ($lexicon as $term) if ((function_exists('mb_stripos') ? mb_stripos($text, $term, 0, 'UTF-8') : stripos($text, $term)) !== false) return true;
        return false;
    }
}
