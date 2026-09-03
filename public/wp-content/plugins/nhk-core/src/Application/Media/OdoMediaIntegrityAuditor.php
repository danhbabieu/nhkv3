<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

/** Read-only comparison of WordPress attachment metadata and upload files. */
final class OdoMediaIntegrityAuditor
{
    /** @param list<array<string,mixed>> $attachments @param list<string> $filesystemFiles @param list<string> $inlineMediaUrls @param list<int> $featuredAttachmentIds @param list<int>|null $referencedAttachmentIds */
    public function audit(array $attachments, array $filesystemFiles, array $inlineMediaUrls = [], array $featuredAttachmentIds = [], ?array $referencedAttachmentIds = null): array
    {
        $files = array_values(array_unique(array_map(static fn($file): string => ltrim((string) $file, '/'), $filesystemFiles)));
        $referenced = [];
        $rows = [];
        $categories = [];
        foreach ($attachments as $attachment) {
            $db = ltrim((string) ($attachment['attached_file'] ?? ''), '/');
            $metadata = is_array($attachment['metadata'] ?? null) ? $attachment['metadata'] : [];
            $metadataFile = ltrim((string) ($metadata['file'] ?? $db), '/');
            $referenced = array_merge($referenced, array_values(array_unique(array_filter([$db, $metadataFile]))));
            $canonical = self::canonical($db);
            $legacy = self::legacy($db);
            $hasDb = $db !== '' && in_array($db, $files, true);
            $hasCanonical = $canonical !== '' && in_array($canonical, $files, true);
            $hasLegacy = $legacy !== '' && in_array($legacy, $files, true);
            $classification = 'OK';
            if ($hasCanonical && $hasLegacy && $canonical !== $legacy) $classification = 'BOTH_DIFFERENT';
            elseif ($db !== '' && $db === $canonical && $hasLegacy && !$hasCanonical) $classification = 'DB_CANONICAL_FS_LEGACY';
            elseif ($db !== '' && $db === $legacy && $hasCanonical && !$hasLegacy) $classification = 'DB_LEGACY_FS_CANONICAL';
            elseif ($db !== '' && $hasDb && ($db === $canonical || $db === $legacy)) $classification = 'BOTH_IDENTICAL';
            $rowCategories = [$classification];
            if ($db !== '' && !$hasDb) $rowCategories[] = 'MISSING_ORIGINAL';
            foreach (is_array($metadata['sizes'] ?? null) ? $metadata['sizes'] : [] as $size) {
                if (!is_array($size)) continue;
                $derivative = ltrim((string) ($size['file'] ?? ''), '/');
                if ($derivative === '') continue;
                $path = str_contains($derivative, '/') ? $derivative : (dirname($db) === '.' ? $derivative : dirname($db) . '/' . $derivative);
                if (!in_array($path, $files, true)) $rowCategories[] = 'MISSING_DERIVATIVE'; else $referenced[] = $path;
            }
            foreach ($rowCategories as $category) $categories[] = $category;
            if ($referencedAttachmentIds !== null && !in_array((int) ($attachment['attachment_id'] ?? 0), $referencedAttachmentIds, true)) {
                $rowCategories[] = 'ORPHAN_ATTACHMENT';
                $categories[] = 'ORPHAN_ATTACHMENT';
            }
            $rows[] = ['attachment_id' => (int) ($attachment['attachment_id'] ?? 0), 'db_path' => $db, 'filesystem_paths' => array_values(array_filter([$hasDb ? $db : null, $hasCanonical && $canonical !== $db ? $canonical : null, $hasLegacy && $legacy !== $db ? $legacy : null])), 'classification' => $classification, 'categories' => array_values(array_unique($rowCategories))];
        }
        foreach ($inlineMediaUrls as $url) if (preg_match('/(?:^|\/)o-do(?:[-.\/]|$)/i', (string) $url) === 1) $categories[] = 'INLINE_LEGACY_URL';
        $referenced = array_values(array_unique(array_filter($referenced)));
        foreach ($files as $file) if (!in_array($file, $referenced, true)) $categories[] = 'ORPHAN_FILE';
        return ['read_only' => true, 'categories' => array_values(array_unique($categories)), 'attachments' => $rows, 'inline_media_urls' => array_values(array_filter($inlineMediaUrls, 'is_string')), 'featured_attachment_ids' => array_values(array_map('intval', $featuredAttachmentIds)), 'orphan_files' => array_values(array_diff($files, $referenced))];
    }

    private static function canonical(string $path): string { return preg_replace('/o-do(?=[-.\/]|$)/i', 'odo', $path) ?? $path; }
    private static function legacy(string $path): string { return preg_replace('/odo(?=[-.\/]|$)/i', 'o-do', $path) ?? $path; }
}
