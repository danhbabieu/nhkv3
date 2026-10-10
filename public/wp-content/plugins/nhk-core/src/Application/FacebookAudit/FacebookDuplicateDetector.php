<?php
declare(strict_types=1);

namespace NHK\Core\Application\FacebookAudit;

final class FacebookDuplicateDetector
{
    /** @param array<string,mixed> $row */
    public function fingerprint(array $row): string
    {
        $text = $this->normalizeText((string) ($row['text'] ?? ''));
        $media = array_values(array_unique(array_map('strval', (array) ($row['media_references'] ?? []))));
        sort($media);
        return hash('sha256', $text . "\n" . implode('|', $media));
    }

    /** @param list<array<string,mixed>> $rows @return list<array{fingerprint:string,post_ids:list<string>}> */
    public function groups(array $rows): array
    {
        $byFingerprint = [];
        foreach ($rows as $row) {
            $fingerprint = $this->fingerprint($row);
            $id = trim((string) ($row['post_id'] ?? ''));
            if ($id === '') continue;
            $byFingerprint[$fingerprint][] = $id;
        }
        $groups = [];
        foreach ($byFingerprint as $fingerprint => $ids) if (count($ids) > 1) $groups[] = ['fingerprint' => $fingerprint, 'post_ids' => array_values(array_unique($ids))];
        usort($groups, static fn (array $a, array $b): int => strcmp($a['fingerprint'], $b['fingerprint']));
        return $groups;
    }

    private function normalizeText(string $text): string
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower(trim($text), 'UTF-8') : strtolower(trim($text));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}
