<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\Media;

/** The single application boundary used by all Media intake adapters. */
final class MediaIngestGateway
{
    public function __construct(private MediaService $service, private ?\NHK\Core\Contracts\Media\WordPressArticleMediaAdapter $wordpress = null) {}

    /** @param array<string,mixed> $packet */
    public function ingest(array $packet): Media
    {
        $media = $this->service->ingest(
            (string) ($packet['stable_key'] ?? ''),
            (string) ($packet['name'] ?? ''),
            (string) ($packet['readiness'] ?? 'draft'),
            is_array($packet['provenance'] ?? null) ? $packet['provenance'] : [],
            is_array($packet['assets'] ?? null) ? $packet['assets'] : [],
            is_array($packet['usages'] ?? null) ? $packet['usages'] : [],
        );
        if ($this->wordpress !== null) {
            $assets = $this->service->assets($media->canonicalId);
            foreach ($assets as $index => $asset) {
                $spec = is_array($packet['assets'][$index] ?? null) ? $packet['assets'][$index] : [];
                $filePath = trim((string) ($spec['file_path'] ?? ''));
                $requiresAttachment = $filePath !== '' || (int) ($spec['wordpress_attachment_id'] ?? 0) > 0;
                if (!$requiresAttachment) continue;
                $this->wordpress->attachmentForMedia($media, $asset, '', [
                    'file_path' => $filePath,
                    'wordpress_attachment_id' => (int) ($spec['wordpress_attachment_id'] ?? 0),
                    'original_filename' => (string) ($spec['original_filename'] ?? basename($asset->storageKey)),
                    'view' => (string) (($spec['metadata']['view'] ?? $spec['metadata']['detail_type'] ?? 'image')),
                    'filename_suffix' => (string) ($spec['metadata']['filename_suffix'] ?? ''),
                ]);
            }
        }
        return $media;
    }
}
