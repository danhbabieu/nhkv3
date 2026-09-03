<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Application\Media\{MediaFilenameNormalizer, MediaService};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, WordPressArticleMediaAdapter};
use NHK\Core\Domain\Media\{Media, MediaAsset};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * The only adapter allowed to translate canonical Media into WordPress
 * editorial attachments and image blocks.
 */
final class WordPressMediaAttachmentBridge implements WordPressArticleMediaAdapter
{
    private string $table;
    private int $controlledWriteDepth = 0;

    public function __construct(
        private object $database,
        private MediaService $mediaService,
        private MediaRepository $media,
        private MediaAssetRepository $assets,
    ) {
        $this->table = $database->prefix . 'nhk_media_wordpress_attachments';
    }

    public function read(int $postId): array
    {
        $content = function_exists('get_post_field') ? (string) get_post_field('post_content', $postId) : '';
        $featuredAttachmentId = function_exists('get_post_thumbnail_id') ? (int) get_post_thumbnail_id($postId) : 0;
        $inlineAttachmentIds = $this->inlineAttachmentIds($content);
        $inlineMediaIds = [];
        $unmapped = [];
        foreach (array_values(array_unique(array_filter(array_merge($featuredAttachmentId > 0 ? [$featuredAttachmentId] : [], $inlineAttachmentIds)))) as $attachmentId) {
            $mediaId = $this->mediaIdForAttachment((int) $attachmentId);
            if ($mediaId === null) { $unmapped[] = (int) $attachmentId; continue; }
            if (in_array((int) $attachmentId, $inlineAttachmentIds, true)) $inlineMediaIds[] = $mediaId;
        }
        $managedAttachmentId = $this->managedInlineAttachmentId($content);
        return [
            'featured_media_id' => $featuredAttachmentId > 0 ? $this->mediaIdForAttachment($featuredAttachmentId) : null,
            'inline_media_ids' => array_values(array_unique($inlineMediaIds)),
            'managed_inline_media_id' => $managedAttachmentId > 0 ? $this->mediaIdForAttachment($managedAttachmentId) : null,
            'featured_attachment_id' => $featuredAttachmentId,
            'inline_attachment_ids' => $inlineAttachmentIds,
            'content' => $content,
            'state_token' => $this->stateToken($featuredAttachmentId, $content),
            'unmapped_attachment_ids' => array_values(array_unique($unmapped)),
        ];
    }

    public function synchronize(int $postId, array $result): array
    {
        $expectedToken = trim((string) ($result['editorial_state_token'] ?? ''));
        $current = $this->read($postId);
        if ($expectedToken !== '' && !hash_equals((string) ($current['state_token'] ?? ''), $expectedToken)) throw new \RuntimeException('EDITORIAL_STATE_CHANGED');

        $slots = is_array($result['slots'] ?? null) ? $result['slots'] : [];
        $featuredMediaId = (string) ($result['slot_media']['featured_primary'] ?? '');
        $inlineMediaId = (string) ($result['slot_media']['inline_primary'] ?? '');
        if ($featuredMediaId !== '' && !($slots['featured_primary']['placeholder'] ?? true)) {
            $attachment = $this->attachmentRepresentationForMediaId($featuredMediaId, (string) ($slots['featured_primary']['blueprint']['planned_alt_intent'] ?? ''), ['post_id' => $postId]);
            $attachmentId = (int) ($attachment['attachment_id'] ?? 0);
            if ($attachmentId < 1) throw new \RuntimeException('WORDPRESS_FEATURED_ATTACHMENT_UNAVAILABLE');
            if ((int) ($current['featured_attachment_id'] ?? 0) !== $attachmentId) {
                if (!function_exists('set_post_thumbnail')) throw new \RuntimeException('WORDPRESS_FEATURED_SYNC_UNAVAILABLE');
                if (!set_post_thumbnail($postId, $attachmentId)) throw new \RuntimeException('WORDPRESS_FEATURED_SYNC_FAILED');
            }
        }

        $content = (string) ($current['content'] ?? '');
        if ($inlineMediaId !== '' && !($slots['inline_primary']['placeholder'] ?? true)) {
            $attachment = $this->attachmentRepresentationForMediaId($inlineMediaId, (string) ($slots['inline_primary']['blueprint']['planned_alt_intent'] ?? ''), ['post_id' => $postId]);
            $attachmentId = (int) ($attachment['attachment_id'] ?? 0);
            if ($attachmentId < 1) throw new \RuntimeException('WORDPRESS_INLINE_ATTACHMENT_UNAVAILABLE');
            $inlineIds = $this->inlineAttachmentIds($content);
            $managedId = $this->managedInlineAttachmentId($content);
            if (!in_array($attachmentId, $inlineIds, true)) {
                $image = $this->renderImage($attachmentId, (string) ($slots['inline_primary']['blueprint']['planned_alt_intent'] ?? ''));
                if ($managedId > 0) {
                    $content = $this->replaceManagedBlock($content, $image, $attachmentId);
                } elseif ($this->hasMappedInlineMedia($inlineIds, (string) ($current['featured_media_id'] ?? ''))) {
                    // A human-selected, mapped inline image already satisfies
                    // the mandatory editorial role. Never reorder it.
                } else {
                    $content = rtrim($content) . ($content === '' ? '' : "\n\n") . $this->managedBlock($attachmentId, $image);
                }
            }
        } elseif (($slots['inline_primary']['placeholder'] ?? false) && $this->managedInlineAttachmentId($content) > 0) {
            $content = $this->removeManagedBlock($content);
        }

        if ($content !== (string) ($current['content'] ?? '')) {
            if (!function_exists('wp_update_post')) throw new \RuntimeException('WORDPRESS_INLINE_SYNC_UNAVAILABLE');
            $this->controlledWriteDepth++;
            try {
                $updated = wp_update_post(['ID' => $postId, 'post_content' => $content], true);
            } finally { $this->controlledWriteDepth--; }
            if (is_wp_error($updated) || (int) $updated !== $postId) throw new \RuntimeException('WORDPRESS_INLINE_SYNC_FAILED');
        }
        return $this->read($postId);
    }

    public function attachmentForMedia(Media $media, MediaAsset $asset, string $contextualAlt = '', array $context = []): array
    {
        $existing = $this->attachmentIdForMedia($media->canonicalId);
        if ($existing > 0) {
            $this->assertAttachment($existing);
            return $this->representation($existing, $asset, $contextualAlt);
        }

        $existing = $this->attachmentIdForStorageKey($asset->storageKey);
        if ($existing > 0) { $this->assertAttachment($existing); $this->saveMapping($media, $asset, $existing); return $this->representation($existing, $asset, $contextualAlt); }

        $requestedAttachmentId = (int) ($context['wordpress_attachment_id'] ?? 0);
        if ($requestedAttachmentId > 0) {
            $this->assertAttachment($requestedAttachmentId);
            $mappedMedia = $this->mediaIdForAttachment($requestedAttachmentId);
            if ($mappedMedia !== null && $mappedMedia !== $media->canonicalId) throw new \RuntimeException('WORDPRESS_ATTACHMENT_IDENTITY_CONFLICT');
            $this->saveMapping($media, $asset, $requestedAttachmentId);
            return $this->representation($requestedAttachmentId, $asset, $contextualAlt);
        }

        $filePath = trim((string) ($context['file_path'] ?? ''));
        if ($filePath === '' || !is_file($filePath) || !is_readable($filePath) || !function_exists('wp_upload_bits') || !function_exists('wp_insert_attachment')) throw new \RuntimeException('WORDPRESS_MEDIA_ATTACHMENT_UNAVAILABLE');
        $filename = basename($asset->storageKey);
        $original = (string) ($context['original_filename'] ?? $filename);
        if (preg_match('/^(IMG|DSC|DSCF|PXL)[-_]?/i', $original) === 1) {
            $filename = (new MediaFilenameNormalizer())->normalize($media->canonicalName, (string) ($context['view'] ?? 'image'), $original, isset($context['filename_suffix']) ? (string) $context['filename_suffix'] : null);
        }
        $contents = file_get_contents($filePath);
        if (!is_string($contents)) throw new \RuntimeException('WORDPRESS_MEDIA_UPLOAD_READ_FAILED');
        $upload = wp_upload_bits($filename, null, $contents);
        if (!is_array($upload) || !empty($upload['error']) || !is_string($upload['file'] ?? null)) throw new \RuntimeException('WORDPRESS_MEDIA_UPLOAD_FAILED');
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $this->controlledWriteDepth++;
        try {
            $attachmentId = wp_insert_attachment(['post_mime_type' => $asset->mimeType, 'post_title' => $media->canonicalName, 'post_content' => '', 'post_status' => 'inherit'], $upload['file'], 0, true);
            if (is_wp_error($attachmentId) || (int) $attachmentId < 1) throw new \RuntimeException('WORDPRESS_ATTACHMENT_CREATE_FAILED');
            if (function_exists('wp_generate_attachment_metadata')) {
                $metadata = wp_generate_attachment_metadata((int) $attachmentId, $upload['file']);
                if (is_array($metadata) && function_exists('wp_update_attachment_metadata')) wp_update_attachment_metadata((int) $attachmentId, $metadata);
            }
        } finally { $this->controlledWriteDepth--; }
        $this->saveMapping($media, $asset, (int) $attachmentId);
        return $this->representation((int) $attachmentId, $asset, $contextualAlt);
    }

    public function adoptAttachment(int $attachmentId): ?string
    {
        if (WordPressMediaAttachmentWriteGuard::active()) return null;
        if ($this->controlledWriteDepth > 0 || $attachmentId < 1 || !function_exists('get_post')) return $this->mediaIdForAttachment($attachmentId);
        $existing = $this->mediaIdForAttachment($attachmentId);
        if ($existing !== null) return $existing;
        $post = get_post($attachmentId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment' || !str_starts_with(strtolower((string) get_post_mime_type($attachmentId)), 'image/')) return null;
        $relative = function_exists('get_post_meta') ? (string) get_post_meta($attachmentId, '_wp_attached_file', true) : '';
        if ($relative === '') return null;
        $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $baseDir = is_array($upload) ? (string) ($upload['basedir'] ?? '') : '';
        $filePath = $baseDir !== '' ? $baseDir . '/' . ltrim($relative, '/') : '';
        $metadata = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($attachmentId) : [];
        $width = is_array($metadata) && isset($metadata['width']) ? (int) $metadata['width'] : null;
        $height = is_array($metadata) && isset($metadata['height']) ? (int) $metadata['height'] : null;
        $checksum = is_file($filePath) ? hash_file('sha256', $filePath) : hash('sha256', 'wp-attachment:' . $attachmentId);
        if (!is_string($checksum)) return null;
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        $media = $this->mediaService->ingest('wp-attachment:' . max(1, $blogId) . ':' . $attachmentId, (string) ($post->post_title ?: basename($relative)), 'draft', ['source' => 'wordpress_attachment_adoption', 'wordpress_attachment_id' => $attachmentId], [[
            'kind' => 'original', 'storage_key' => 'uploads/' . ltrim($relative, '/'), 'original_filename' => basename($relative), 'checksum' => $checksum, 'mime_type' => (string) get_post_mime_type($attachmentId), 'byte_size' => is_file($filePath) ? (int) filesize($filePath) : 0, 'width' => $width, 'height' => $height, 'visibility' => 'PRIVATE',
        ]]);
        $asset = $this->assets->listByMediaId($media->canonicalId)[0] ?? null;
        if ($asset instanceof MediaAsset) $this->saveMapping($media, $asset, $attachmentId);
        return $media->canonicalId;
    }

    /** @return array<string,mixed> */
    public function representationForMedia(Media $media, MediaAsset $asset, string $contextualAlt = ''): array
    {
        try { return $this->representation($this->attachmentIdForMedia($media->canonicalId), $asset, $contextualAlt); } catch (\Throwable) { return []; }
    }

    public function isHandlingWrite(): bool { return $this->controlledWriteDepth > 0; }

    /** @return array<string,mixed> */
    private function attachmentRepresentationForMediaId(string $mediaId, string $alt, array $context): array
    {
        $media = $this->media->findByCanonicalId($mediaId);
        $asset = $this->assets->listByMediaId($mediaId)[0] ?? null;
        if (!$media instanceof Media || !$asset instanceof MediaAsset) throw new \RuntimeException('WORDPRESS_MEDIA_ASSET_UNAVAILABLE');
        return $this->attachmentForMedia($media, $asset, $alt, $context);
    }

    /** @return array<string,mixed> */
    private function representation(int $attachmentId, MediaAsset $asset, string $alt): array
    {
        $this->assertAttachment($attachmentId);
        $src = function_exists('wp_get_attachment_image_src') ? wp_get_attachment_image_src($attachmentId, 'large') : false;
        $url = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($attachmentId) : '';
        if (function_exists('wp_get_attachment_url') && $url === '') throw new \RuntimeException('WORDPRESS_MEDIA_ATTACHMENT_UNAVAILABLE');
        $width = is_array($src) ? (int) ($src[1] ?? 0) : (int) ($asset->width ?? 0);
        $height = is_array($src) ? (int) ($src[2] ?? 0) : (int) ($asset->height ?? 0);
        return ['attachment_id' => $attachmentId, 'url' => $url, 'src' => is_array($src) ? (string) ($src[0] ?? $url) : $url, 'srcset' => function_exists('wp_get_attachment_image_srcset') ? (string) wp_get_attachment_image_srcset($attachmentId, 'large') : '', 'sizes' => function_exists('wp_get_attachment_image_sizes') ? (string) wp_get_attachment_image_sizes($attachmentId, 'large') : '', 'width' => $width, 'height' => $height, 'alt' => $alt];
    }

    private function assertAttachment(int $attachmentId): void
    {
        if ($attachmentId < 1) throw new \RuntimeException('WORDPRESS_MEDIA_ATTACHMENT_UNAVAILABLE');
        if (!function_exists('get_post')) return;
        $post = get_post($attachmentId);
        if (!is_object($post) || (string) ($post->post_type ?? '') !== 'attachment') throw new \RuntimeException('WORDPRESS_MEDIA_ATTACHMENT_UNAVAILABLE');
    }

    private function renderImage(int $attachmentId, string $alt): string
    {
        if (function_exists('wp_get_attachment_image')) return (string) wp_get_attachment_image($attachmentId, 'large', false, ['alt' => $alt, 'loading' => 'lazy']);
        return '';
    }

    private function managedBlock(int $attachmentId, string $image): string
    {
        return '<!-- wp:image {"id":' . $attachmentId . ',"sizeSlug":"large","linkDestination":"none","className":"nhk-managed-inline-primary"} -->' . $image . '<!-- /wp:image -->';
    }

    private function replaceManagedBlock(string $content, string $image, int $attachmentId): string
    {
        $replacement = $this->managedBlock($attachmentId, $image);
        $updated = preg_replace('/<!-- wp:image\b[^>]*nhk-managed-inline-primary[^>]*-->.*?<!-- \/wp:image -->/is', $replacement, $content, 1);
        return is_string($updated) ? $updated : $content;
    }

    private function removeManagedBlock(string $content): string
    {
        $updated = preg_replace('/\s*<!-- wp:image\b[^>]*nhk-managed-inline-primary[^>]*-->.*?<!-- \/wp:image -->\s*/is', "\n", $content, 1);
        return is_string($updated) ? trim($updated) : $content;
    }

    /** @return list<int> */
    private function inlineAttachmentIds(string $content): array
    {
        $ids = [];
        if (preg_match_all('/(?:wp-image-|"id"\s*:\s*|data-id=["\'])([1-9][0-9]*)/i', $content, $matches)) foreach ($matches[1] as $id) $ids[] = (int) $id;
        return array_values(array_unique($ids));
    }

    private function managedInlineAttachmentId(string $content): int
    {
        if (preg_match('/<!-- wp:image\b[^>]*"id"\s*:\s*([1-9][0-9]*)[^>]*nhk-managed-inline-primary[^>]*-->/i', $content, $match) === 1) return (int) $match[1];
        return 0;
    }

    private function hasMappedInlineMedia(array $inlineIds, string $featuredMediaId): bool
    {
        foreach ($inlineIds as $id) { $mediaId = $this->mediaIdForAttachment((int) $id); if ($mediaId !== null && $mediaId !== $featuredMediaId) return true; }
        return false;
    }

    private function mediaIdForAttachment(int $attachmentId): ?string
    {
        if ($attachmentId < 1) return null;
        $value = $this->database->get_var($this->database->prepare("SELECT media_uuid FROM {$this->table} WHERE attachment_id=%d LIMIT 1", $attachmentId));
        return is_string($value) && strlen($value) === 16 ? UuidCodec::fromBinary($value) : null;
    }

    private function attachmentIdForMedia(string $mediaId): int
    {
        return (int) $this->database->get_var($this->database->prepare("SELECT attachment_id FROM {$this->table} WHERE media_uuid=%s LIMIT 1", UuidCodec::toBinary($mediaId)));
    }

    private function attachmentIdForStorageKey(string $storageKey): int
    {
        $relative = preg_replace('#^uploads/#', '', ltrim(str_replace('\\', '/', $storageKey), '/'));
        return (int) $this->database->get_var($this->database->prepare("SELECT p.ID FROM {$this->database->posts} p INNER JOIN {$this->database->postmeta} pm ON pm.post_id=p.ID WHERE p.post_type='attachment' AND pm.meta_key='_wp_attached_file' AND pm.meta_value=%s ORDER BY p.ID ASC LIMIT 1", (string) $relative));
    }

    private function saveMapping(Media $media, MediaAsset $asset, int $attachmentId): void
    {
        $mappedMedia = $this->mediaIdForAttachment($attachmentId);
        if ($mappedMedia !== null && $mappedMedia !== $media->canonicalId) throw new \RuntimeException('WORDPRESS_ATTACHMENT_IDENTITY_CONFLICT');
        $ok = $this->database->query($this->database->prepare("INSERT INTO {$this->table} (media_uuid,asset_uuid,attachment_id,storage_key,created_at,updated_at) VALUES (%s,%s,%d,%s,%s,%s) ON DUPLICATE KEY UPDATE asset_uuid=VALUES(asset_uuid),storage_key=VALUES(storage_key),updated_at=VALUES(updated_at)", UuidCodec::toBinary($media->canonicalId), UuidCodec::toBinary($asset->assetId), $attachmentId, $asset->storageKey, gmdate('Y-m-d H:i:s.u'), gmdate('Y-m-d H:i:s.u')));
        if ($ok === false) throw new \RuntimeException('WORDPRESS_MEDIA_MAPPING_SAVE_FAILED');
    }

    private function stateToken(int $featuredAttachmentId, string $content): string
    {
        return hash('sha256', (string) json_encode(['featured_attachment_id' => $featuredAttachmentId, 'content' => $content], JSON_UNESCAPED_SLASHES));
    }
}
