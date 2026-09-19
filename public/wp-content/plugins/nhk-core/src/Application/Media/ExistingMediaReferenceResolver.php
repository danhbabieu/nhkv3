<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, WordPressMediaAttachmentIngestor};
use NHK\Core\Domain\Media\{Media, MediaAsset};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Read-only bridge from an existing canonical Media identity to the asset
 * manifest already consumed by Capture. It never downloads or creates data.
 */
final class ExistingMediaReferenceResolver
{
    public function __construct(
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private WordPressMediaAttachmentIngestor $attachments,
    ) {}

    /** @param list<string> $mediaIds @return list<array<string,mixed>> */
    public function resolve(array $mediaIds): array
    {
        if (!array_is_list($mediaIds) || $mediaIds === []) throw new \InvalidArgumentException('MEDIA_REFERENCE_INVALID');
        $seen = [];
        $resolved = [];
        foreach ($mediaIds as $mediaId) {
            $mediaId = trim((string) $mediaId);
            if (!UuidCodec::isValid($mediaId) || isset($seen[$mediaId])) throw new \InvalidArgumentException('MEDIA_REFERENCE_INVALID');
            $seen[$mediaId] = true;
            $media = $this->media->findByCanonicalId($mediaId);
            if (!$media instanceof Media || !$media->active || $media->isSystemPlaceholder()) throw new \InvalidArgumentException('MEDIA_REFERENCE_NOT_FOUND');
            $asset = $this->mappedAsset($mediaId);
            if (!$asset instanceof MediaAsset) {
                $candidateAttachmentId = 0;
                foreach ($this->assets->listByMediaId($mediaId) as $candidate) {
                    if ($candidate instanceof MediaAsset && isset($candidate->metadata['wordpress_attachment_id'])) {
                        $candidateAttachmentId = (int) $candidate->metadata['wordpress_attachment_id'];
                        break;
                    }
                }
                // The canonical bridge may know the exact attachment even
                // when the asset projection is stale. Read it first, then
                // repair only that proven mapping; never infer from names.
                if ($candidateAttachmentId < 1 && method_exists($this->attachments, 'attachmentIdForMediaReference')) $candidateAttachmentId = (int) $this->attachments->attachmentIdForMediaReference($mediaId);
                if ($candidateAttachmentId > 0 && method_exists($this->attachments, 'reconcileBinding')) {
                    ($this->attachments->reconcileBinding($mediaId, $candidateAttachmentId));
                    $asset = $this->mappedAsset($mediaId);
                }
            }
            if (!$asset instanceof MediaAsset) throw new \InvalidArgumentException('MEDIA_ATTACHMENT_BINDING_NOT_FOUND');
            $attachmentId = (int) ($asset->metadata['wordpress_attachment_id'] ?? 0);
            if ($attachmentId < 1) throw new \InvalidArgumentException('MEDIA_ATTACHMENT_BINDING_NOT_FOUND');
            $attachment = $this->attachments->read($attachmentId);
            if (!is_array($attachment) || (int) ($attachment['attachment_id'] ?? 0) !== $attachmentId) throw new \InvalidArgumentException('MEDIA_ATTACHMENT_READBACK_FAILED');
            $readbackMediaId = trim((string) ($attachment['media_id'] ?? ''));
            if ($readbackMediaId !== '' && $readbackMediaId !== $mediaId) throw new \InvalidArgumentException('MEDIA_ATTACHMENT_MAPPING_INCONSISTENT');
            $resolved[] = [
                'client_file_id' => $mediaId,
                'media_id' => $mediaId,
                'attachment_id' => $attachmentId,
                'filename' => (string) ($attachment['filename'] ?? ''),
                'original_filename' => (string) ($attachment['original_filename'] ?? ''),
                'mime_type' => (string) ($attachment['mime'] ?? $asset->mimeType),
                'byte_size' => (int) ($attachment['filesize'] ?? $asset->byteSize),
                'width' => (int) ($attachment['width'] ?? $asset->width ?? 0),
                'height' => (int) ($attachment['height'] ?? $asset->height ?? 0),
                'checksum_sha256' => $asset->checksum,
                'attachment_readback_status' => 'verified',
                'upload_status' => 'REUSED',
                'reused' => true,
                'sort_order' => count($resolved),
            ];
        }
        return $resolved;
    }

    private function mappedAsset(string $mediaId): ?MediaAsset
    {
        foreach ($this->assets->listByMediaId($mediaId) as $asset) {
            if ($asset instanceof MediaAsset && (int) ($asset->metadata['wordpress_attachment_id'] ?? 0) > 0) return $asset;
        }
        return null;
    }
}
