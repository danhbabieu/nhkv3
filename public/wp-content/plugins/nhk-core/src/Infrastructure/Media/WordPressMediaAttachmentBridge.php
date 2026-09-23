<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Media;

use NHK\Core\Application\Media\{MediaFilenameNormalizer, MediaService, PublicImageSizingPolicy, PublicMediaAssetSelector};
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, WordPressArticleMediaAdapter};
use NHK\Core\Domain\Media\{Media, MediaAsset};
use NHK\Core\Infrastructure\Article\WpEditorialStateReader;
use NHK\Core\Shared\Uuid\UuidCodec;
use RuntimeException;

/**
 * The only adapter allowed to translate canonical Media into WordPress
 * editorial attachments and image blocks.
 */
final class WordPressMediaAttachmentBridge implements WordPressArticleMediaAdapter
{
    private string $table;
    private int $controlledWriteDepth = 0;
    private ?float $adoptionStartedAt = null;
    private string $adoptionStage = 'ADOPTION_STARTED';

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
        $editorialState = (new WpEditorialStateReader())->read($postId);
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
            'state_token' => $editorialState !== null ? $editorialState->token : '',
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
        } elseif (($slots['featured_primary']['placeholder'] ?? false)
            && (int) ($current['featured_attachment_id'] ?? 0) > 0
            && $this->managedFeaturedMediaMatches($current, $slots['featured_primary'] ?? [])) {
            if (function_exists('delete_post_thumbnail')) {
                delete_post_thumbnail($postId);
            } elseif (function_exists('set_post_thumbnail')) {
                set_post_thumbnail($postId, 0);
            }
        }

        $content = (string) ($current['content'] ?? '');
        if ($inlineMediaId !== '' && !($slots['inline_primary']['placeholder'] ?? true)) {
            $attachment = $this->attachmentRepresentationForMediaId($inlineMediaId, (string) ($slots['inline_primary']['blueprint']['planned_alt_intent'] ?? ''), ['post_id' => $postId]);
            $attachmentId = (int) ($attachment['attachment_id'] ?? 0);
            if ($attachmentId < 1) throw new \RuntimeException('WORDPRESS_INLINE_ATTACHMENT_UNAVAILABLE');
            $inlineIds = $this->inlineAttachmentIds($content);
            $managedId = $this->managedInlineAttachmentId($content);
            $placementAnchor = trim((string) ($slots['inline_primary']['placement_anchor'] ?? ''));
            if (!in_array($attachmentId, $inlineIds, true)) {
                $image = $this->renderImage($attachmentId, (string) ($slots['inline_primary']['blueprint']['planned_alt_intent'] ?? ''), $placementAnchor);
                if ($managedId > 0) {
                    $content = $this->replaceManagedBlock($content, $image, $attachmentId, $placementAnchor);
                } elseif (($result['force_inline_reconcile'] ?? false) === true && $inlineIds !== []) {
                    $content = $this->replaceFirstImageBlock($content, $image, $attachmentId, $placementAnchor);
                } elseif (!$this->hasMappedInlineMedia($inlineIds, $inlineMediaId) && $inlineIds !== []) {
                    // The canonical MediaUsage target owns the inline slot.
                    // A mapped legacy image is not evidence for the current
                    // target; replace the first existing image block so the
                    // canonical attachment is what the article renders.
                    $content = $this->replaceFirstImageBlock($content, $image, $attachmentId, $placementAnchor);
                } else {
                    $content = rtrim($content) . ($content === '' ? '' : "\n\n") . $this->managedBlock($attachmentId, $image, $placementAnchor);
                }
            } elseif ($placementAnchor !== '') {
                $content = $this->ensurePlacementAnchor($content, $attachmentId, $placementAnchor);
            }
            if (($result['force_inline_reconcile'] ?? false) === true) {
                // Capture reconciliation owns the mandatory inline slot. Once
                // the canonical target is known, every other mapped inline
                // attachment is stale for this Article and must not survive
                // merely because it was already present in post_content.
                $content = $this->removeMappedInlineImagesExcept($content, array_merge((array) ($current['inline_attachment_ids'] ?? []), $this->inlineAttachmentIds($content)), [$attachmentId]);
                $content = $this->removeInlineImagesExcept($content, $attachmentId);
            }
        } elseif (($slots['inline_primary']['placeholder'] ?? false) && ($result['force_inline_reconcile'] ?? false) === true) {
            $managedId = $this->managedInlineAttachmentId($content);
            $managedOwned = $managedId > 0 && $this->managedInlineMediaMatches($managedId, $slots['inline_primary'] ?? [], $content);
            $content = $managedOwned
                ? $this->removeManagedBlock($content)
                : $content;
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
        if (is_array($context['attachment_metadata'] ?? null) && $context['attachment_metadata'] !== []) {
            WordPressMediaAttachmentWriteGuard::enter();
            try { (new WordPressAttachmentMetadataProjection())->apply((int) $attachmentId, $context['attachment_metadata']); }
            finally { WordPressMediaAttachmentWriteGuard::leave(); }
        }
        return $this->representation((int) $attachmentId, $asset, $contextualAlt);
    }

    public function adoptAttachment(int $attachmentId, array $context = []): ?string
    {
        if (WordPressMediaAttachmentWriteGuard::active()) return null;
        if ($this->controlledWriteDepth > 0 || $attachmentId < 1 || !function_exists('get_post')) return $this->mediaIdForAttachment($attachmentId);
        $this->adoptionStartedAt = microtime(true);
        $this->adoptionStage = 'ADOPTION_STARTED';
        try {
        $this->adoptionPhase($attachmentId, 'ADOPTION_STARTED');
        $existing = $this->mediaIdForAttachment($attachmentId);
        $post = get_post($attachmentId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'attachment' || !str_starts_with(strtolower((string) get_post_mime_type($attachmentId)), 'image/')) return null;
        $relative = function_exists('get_post_meta') ? (string) get_post_meta($attachmentId, '_wp_attached_file', true) : '';
        if ($relative === '') return null;
        $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $baseDir = is_array($upload) ? (string) ($upload['basedir'] ?? '') : '';
        $filePath = $baseDir !== '' ? $baseDir . '/' . ltrim($relative, '/') : '';
        if (!is_file($filePath) || !is_readable($filePath)) return null;
        $this->adoptionPhase($attachmentId, 'ATTACHMENT_READBACK_READY', $existing);
        $stableKey = $this->stableKeyForAttachment($attachmentId);
        $existingStable = $this->media->findByStableKey($stableKey);
        $mappedMedia = $existing !== null ? $this->media->findByCanonicalId($existing) : null;
        if ($existing !== null && !$mappedMedia instanceof Media) throw new RuntimeException('WORDPRESS_ATTACHMENT_MEDIA_IDENTITY_UNAVAILABLE');
        if ($mappedMedia instanceof Media && $existingStable instanceof Media && $mappedMedia->canonicalId !== $existingStable->canonicalId) throw new RuntimeException('WORDPRESS_ATTACHMENT_IDENTITY_CONFLICT');
        $existingMedia = $mappedMedia instanceof Media ? $mappedMedia : $existingStable;
        if ($existingMedia instanceof Media) $this->adoptionPhase($attachmentId, 'MEDIA_IDENTITY_READY', $existingMedia->canonicalId);
        $this->adoptionPhase($attachmentId, 'EXISTING_MAPPING_INSPECTED', $existingMedia?->canonicalId, ['mapped' => $mappedMedia instanceof Media, 'stable_key_match' => $existingStable instanceof Media]);
        if ($existingMedia instanceof Media) {
            $source = null;
            $derivative = null;
            foreach ($this->assets->listByMediaId($existingMedia->canonicalId) as $candidate) {
                if ($candidate->kind === 'original' && $candidate->visibility === 'PRIVATE' && (int) ($candidate->metadata['wordpress_attachment_id'] ?? 0) === $attachmentId && ($candidate->metadata['source_original'] ?? false) === true) $source = $candidate;
                if ((int) ($candidate->metadata['wordpress_source_attachment_id'] ?? 0) === $attachmentId && $candidate->mimeType === 'image/webp' && $candidate->visibility === 'PUBLIC') $derivative = $candidate;
            }
            if ($source instanceof MediaAsset && $derivative instanceof MediaAsset) {
                if ($existingMedia->readiness !== 'ready') $existingMedia = $this->mediaService->update($existingMedia->canonicalId, $existingMedia->canonicalName, 'ready', $existingMedia->provenance, $existingMedia->revision);
                $this->saveMapping($existingMedia, $source, $attachmentId);
                $this->adoptionPhase($attachmentId, 'SOURCE_ORIGINAL_READY', $existingMedia->canonicalId);
                $this->adoptionPhase($attachmentId, 'PUBLIC_DERIVATIVE_READY', $existingMedia->canonicalId);
                $this->adoptionPhase($attachmentId, 'ATTACHMENT_BINDING_READY', $existingMedia->canonicalId);
                $this->adoptionPhase($attachmentId, 'COMPLETE', $existingMedia->canonicalId);
                return $existingMedia->canonicalId;
            }
        }
        $metadata = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($attachmentId) : [];
        $width = is_array($metadata) && isset($metadata['width']) ? (int) $metadata['width'] : null;
        $height = is_array($metadata) && isset($metadata['height']) ? (int) $metadata['height'] : null;
        $mime = strtolower((string) get_post_mime_type($attachmentId));
        if ($mime !== 'image/webp') return $this->adoptExistingRasterAttachment($attachmentId, $post, $filePath, $context, $existingMedia);
        if ($width < 1 || $height < 1) return null;
        $checksum = hash_file('sha256', $filePath);
        $byteSize = filesize($filePath);
        if (!is_string($checksum) || $checksum === '' || $byteSize === false || $byteSize < 1) return null;
        $originalFilename = function_exists('get_post_meta') ? trim((string) get_post_meta($attachmentId, '_nhk_original_filename', true)) : '';
        if ($originalFilename === '') $originalFilename = basename($relative);
        $sourceRelative = function_exists('get_post_meta') ? trim((string) get_post_meta($attachmentId, '_nhk_source_original_file', true)) : '';
        $sourceSpec = $this->sourceAssetSpec($sourceRelative, $originalFilename);
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        $canonicalFilename = (new MediaFilenameNormalizer())->normalizeWebp((string) ($post->post_title ?: basename($relative)), 'image', basename($relative));
        $assets = [[
            'kind' => $sourceSpec !== null ? 'derivative' : 'original', 'storage_key' => 'uploads/' . ltrim($relative, '/'), 'original_filename' => $originalFilename, 'checksum' => $checksum, 'mime_type' => $mime, 'byte_size' => (int) $byteSize, 'width' => $width, 'height' => $height, 'visibility' => 'PUBLIC', 'metadata' => ['canonical_filename' => $canonicalFilename, 'original_filename' => $originalFilename, 'sizes' => [], 'wordpress_attachment_id' => $attachmentId],
        ]];
        if ($sourceSpec !== null) array_unshift($assets, $sourceSpec);
        $media = $this->mediaService->ingest('wp-attachment:' . max(1, $blogId) . ':' . $attachmentId, (string) ($post->post_title ?: basename($relative)), 'draft', ['source' => 'wordpress_attachment_adoption', 'wordpress_attachment_id' => $attachmentId], $assets);
        $asset = null;
        foreach ($this->assets->listByMediaId($media->canonicalId) as $candidate) if ($candidate->storageKey === 'uploads/' . ltrim($relative, '/')) { $asset = $candidate; break; }
        if (!$asset instanceof MediaAsset) throw new \RuntimeException('WORDPRESS_MEDIA_ASSET_UNAVAILABLE');
        $media = $this->mediaService->completeIngest($media->canonicalId, $asset->assetId);
        $asset = $this->assets->findByAssetId($asset->assetId) ?? $asset;
        $this->saveMapping($media, $asset, $attachmentId);
        $this->adoptionPhase($attachmentId, 'ATTACHMENT_BINDING_READY', $media->canonicalId);
        $this->adoptionPhase($attachmentId, 'COMPLETE', $media->canonicalId);
        return $media->canonicalId;
        } catch (\Throwable $error) {
            $this->adoptionPhase($attachmentId, 'FAILED', $this->mediaIdForAttachment($attachmentId), ['failed_stage' => $this->adoptionStage, 'error_code' => $error->getMessage()]);
            throw $error;
        } finally {
            $this->adoptionStartedAt = null;
            $this->adoptionStage = 'ADOPTION_STARTED';
        }
    }

    /**
     * Adopt an existing JPEG/PNG/etc. attachment without uploading it again.
     * The attachment remains the physical WordPress locator; V3 retains a
     * private source copy and creates the canonical public WebP derivative
     * under the same Media identity.
     */
    private function adoptExistingRasterAttachment(int $attachmentId, object $post, string $filePath, array $context, ?Media $existingMedia = null): ?string
    {
        if (!is_file($filePath) || !is_readable($filePath) || !function_exists('wp_get_image_editor')) return null;
        $info = @getimagesize($filePath);
        if (!is_array($info) || !is_string($info['mime'] ?? null) || (int) ($info[0] ?? 0) < 1 || (int) ($info[1] ?? 0) < 1) return null;
        $sourceContents = file_get_contents($filePath);
        if (!is_string($sourceContents) || $sourceContents === '') throw new RuntimeException('WORDPRESS_MEDIA_SOURCE_READ_FAILED');

        $originalFilename = function_exists('get_post_meta') ? trim((string) get_post_meta($attachmentId, '_nhk_original_filename', true)) : '';
        if ($originalFilename === '') $originalFilename = basename($filePath);
        $canonicalName = trim((string) ($context['canonical_name'] ?? ''));
        if ($canonicalName === '') $canonicalName = $existingMedia instanceof Media ? $existingMedia->canonicalName : (string) ($post->post_title ?? $originalFilename);
        $requestedSlug = trim((string) ($context['seo_slug'] ?? ''));
        $canonicalFilename = (new MediaFilenameNormalizer())->normalizeWebp($requestedSlug !== '' ? $requestedSlug : $canonicalName, '', $originalFilename);
        $canonicalFilename = $this->collisionSafeCanonicalFilename($canonicalFilename, $attachmentId, $existingMedia);

        $sourceStorage = PrivateMediaSourceStorage::fromWordPress();
        $sourceExtension = $this->sourceExtension((string) $info['mime']);
        $sourceKey = 'private/' . hash('sha256', $sourceContents) . '.' . $sourceExtension;
        $sourceCreated = $sourceStorage->path($sourceKey) === null;
        $this->adoptionPhase($attachmentId, 'SOURCE_ORIGINAL_PERSISTENCE_STARTED', $existingMedia?->canonicalId);
        $sourceKey = $sourceStorage->store($sourceContents, $sourceExtension);
        $this->adoptionPhase($attachmentId, 'SOURCE_ORIGINAL_PERSISTED', $existingMedia?->canonicalId);

        $mediaDescription = trim((string) ($context['description'] ?? ''));
        $sourceMetadata = ['source_original' => true, 'original_filename' => $originalFilename, 'wordpress_attachment_id' => $attachmentId];
        if ($mediaDescription !== '') $sourceMetadata['description'] = $mediaDescription;
        $sourceAsset = [
            'kind' => 'original', 'storage_key' => $sourceKey, 'original_filename' => $originalFilename,
            'checksum' => hash('sha256', $sourceContents), 'mime_type' => strtolower((string) $info['mime']),
            'byte_size' => strlen($sourceContents), 'width' => (int) $info[0], 'height' => (int) $info[1],
            'visibility' => 'PRIVATE', 'metadata' => $sourceMetadata,
        ];
        $media = null;
        try {
            // Persist the source and resolve Media before the expensive image
            // editor boundary. A retry can therefore resume derivative work
            // with the same Media UUID after a timeout or editor failure.
            $this->adoptionPhase($attachmentId, 'MEDIA_IDENTITY_CREATE_OR_RESOLVE_STARTED', $existingMedia?->canonicalId);
            $media = $this->mediaService->ingest(
                $existingMedia instanceof Media ? $existingMedia->stableKey : $this->stableKeyForAttachment($attachmentId),
                $canonicalName,
                'draft',
                ['source' => 'wordpress_existing_attachment_url', 'wordpress_attachment_id' => $attachmentId],
                [$sourceAsset],
            );
            $this->adoptionPhase($attachmentId, 'MEDIA_IDENTITY_READY', $media->canonicalId);

            $storageRoot = $this->publicMediaStorageRoot();
            $storageKey = 'nhk-public/' . $canonicalFilename;
            $publicPath = $storageRoot . '/' . $storageKey;
            $publicDirectory = dirname($publicPath);
            if (!is_dir($publicDirectory) && !mkdir($publicDirectory, 0755, true) && !is_dir($publicDirectory)) throw new RuntimeException('WORDPRESS_MEDIA_PUBLIC_STORAGE_UNAVAILABLE');
            $createdPublic = false;
            $this->adoptionPhase($attachmentId, 'WEBP_DERIVATIVE_GENERATION_STARTED', $media->canonicalId);
            if (!is_file($publicPath) || @getimagesize($publicPath) === false) {
                $normalizedSource = $this->normalizePaletteSource($filePath);
                try {
                    $editor = wp_get_image_editor($normalizedSource ?? $filePath);
                    if (is_wp_error($editor)) throw new RuntimeException('WORDPRESS_MEDIA_EDITOR_UNAVAILABLE');
                    $target = PublicImageSizingPolicy::constrain((int) $info[0], (int) $info[1]);
                    $resized = $editor->resize($target['width'], $target['height'], false);
                    if (is_wp_error($resized)) throw new RuntimeException('WORDPRESS_MEDIA_RESIZE_FAILED');
                    if ($editor->set_quality(PublicMediaAssetSelector::DEFAULT_WEBP_QUALITY) === false) throw new RuntimeException('WORDPRESS_MEDIA_QUALITY_FAILED');
                    $saved = $editor->save($publicPath, 'image/webp');
                    if (is_wp_error($saved) || !is_array($saved) || strtolower((string) ($saved['mime-type'] ?? '')) !== 'image/webp' || (string) ($saved['path'] ?? '') !== $publicPath) throw new RuntimeException('WORDPRESS_MEDIA_WEBP_UNAVAILABLE');
                    $createdPublic = true;
                } finally {
                    if ($normalizedSource !== null && is_file($normalizedSource)) @unlink($normalizedSource);
                }
            }
            $publicInfo = @getimagesize($publicPath);
            if (!is_array($publicInfo) || strtolower((string) ($publicInfo['mime'] ?? '')) !== 'image/webp') throw new RuntimeException('WORDPRESS_MEDIA_PUBLIC_DERIVATIVE_INVALID');
            $publicChecksum = hash_file('sha256', $publicPath);
            $publicSize = filesize($publicPath);
            if (!is_string($publicChecksum) || $publicChecksum === '' || $publicSize === false || $publicSize < 1) throw new RuntimeException('WORDPRESS_MEDIA_PUBLIC_DERIVATIVE_READBACK_FAILED');
            $this->adoptionPhase($attachmentId, 'PUBLIC_DERIVATIVE_READY', $media->canonicalId);

            $derivativeMetadata = ['canonical_filename' => $canonicalFilename, 'derived_from' => $sourceKey, 'wordpress_source_attachment_id' => $attachmentId, 'quality' => PublicMediaAssetSelector::DEFAULT_WEBP_QUALITY];
            if ($mediaDescription !== '') $derivativeMetadata['description'] = $mediaDescription;
            $derivativeAsset = [
                'kind' => 'derivative', 'storage_key' => $storageKey, 'original_filename' => $originalFilename,
                'checksum' => $publicChecksum, 'mime_type' => 'image/webp', 'byte_size' => (int) $publicSize,
                'width' => (int) $publicInfo[0], 'height' => (int) $publicInfo[1], 'visibility' => 'PUBLIC',
                'metadata' => $derivativeMetadata,
            ];
            $this->adoptionPhase($attachmentId, 'PUBLIC_DERIVATIVE_PERSISTENCE_STARTED', $media->canonicalId);
            $media = $this->mediaService->ingest($media->stableKey, $media->canonicalName, 'draft', $media->provenance, [$derivativeAsset]);
            $media = $this->mediaService->update($media->canonicalId, $media->canonicalName, 'ready', $media->provenance, $media->revision);
            $source = null;
            foreach ($this->assets->listByMediaId($media->canonicalId) as $asset) if ($asset->storageKey === $sourceKey) { $source = $asset; break; }
            if (!$source instanceof MediaAsset) throw new RuntimeException('WORDPRESS_MEDIA_SOURCE_ASSET_UNAVAILABLE');
            $this->adoptionPhase($attachmentId, 'SOURCE_ASSET_READY', $media->canonicalId);
            $this->adoptionPhase($attachmentId, 'ATTACHMENT_BINDING_STARTED', $media->canonicalId);
            $this->saveMapping($media, $source, $attachmentId);
            $this->adoptionPhase($attachmentId, 'ATTACHMENT_BINDING_READY', $media->canonicalId);
            $this->adoptionPhase($attachmentId, 'COMPLETE', $media->canonicalId);
            return $media->canonicalId;
        } catch (\Throwable $error) {
            if ($media === null && $sourceCreated) {
                try { $sourceStorage->delete($sourceKey); } catch (\Throwable) { }
            }
            throw $error;
        }

    }

    private function publicMediaStorageRoot(): string
    {
        $configured = defined('NHK_MEDIA_STORAGE_ROOT') ? (string) NHK_MEDIA_STORAGE_ROOT : (string) (getenv('NHK_MEDIA_STORAGE_ROOT') ?: '');
        if ($configured !== '') return rtrim($configured, '/\\');
        $upload = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
        $root = is_array($upload) ? (string) ($upload['basedir'] ?? '') : '';
        if ($root === '') throw new RuntimeException('WORDPRESS_MEDIA_PUBLIC_STORAGE_UNAVAILABLE');
        return rtrim($root, '/\\');
    }

    private function normalizePaletteSource(string $filePath): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imageistruecolor') || !function_exists('imagecreatetruecolor')) return null;
        $bytes = @file_get_contents($filePath);
        if (!is_string($bytes) || $bytes === '') return null;
        $source = @imagecreatefromstring($bytes);
        if (!is_object($source) && !is_resource($source)) return null;
        if (imageistruecolor($source)) return null;
        $normalized = imagecreatetruecolor(imagesx($source), imagesy($source));
        if (!$normalized) return null;
        imagealphablending($normalized, false);
        imagesavealpha($normalized, true);
        imagecopy($normalized, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
        $path = tempnam(sys_get_temp_dir(), 'nhk-webp-');
        if (!is_string($path) || !@imagepng($normalized, $path)) {
            if (is_file((string) $path)) @unlink((string) $path);
            return null;
        }
        return $path;
    }

    private function sourceExtension(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', default => 'jpg',
        };
    }

    private function stableKeyForAttachment(int $attachmentId): string
    {
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
        return 'wp-attachment:' . max(1, $blogId) . ':' . $attachmentId;
    }

    private function collisionSafeCanonicalFilename(string $filename, int $attachmentId, ?Media $existingMedia = null): string
    {
        // A mapped Media already owns this canonical filename. Do not scan
        // every Media/asset row on a replay just to return the same value;
        // that unbounded read was the hot path behind slow existing-URL work.
        if ($existingMedia instanceof Media || $this->mediaIdForAttachment($attachmentId) !== null) return $filename;
        $existing = [];
        foreach ($this->media->list() as $media) {
            foreach ($this->assets->listByMediaId($media->canonicalId) as $asset) {
                $candidate = trim((string) ($asset->metadata['canonical_filename'] ?? ''));
                if ($candidate !== '') $existing[] = $candidate;
            }
        }
        return (new \NHK\Core\Application\Media\PublicMediaAssetUrlResolver())->collisionSafeFilename($filename, array_values(array_unique($existing)));
    }

    private function sourceAssetSpec(string $relative, string $originalFilename = ''): ?array
    {
        if ($relative === '' || !str_starts_with($relative, 'private/')) return null;
        $path = PrivateMediaSourceStorage::fromWordPress()->path($relative);
        if ($path === null || !is_readable($path)) return null;
        $info = @getimagesize($path); $checksum = hash_file('sha256', $path); $size = filesize($path);
        if (!is_array($info) || !is_string($info['mime'] ?? null) || !is_string($checksum) || $checksum === '' || $size === false || $size < 1) return null;
        $originalFilename = $originalFilename !== '' ? $originalFilename : basename($relative);
        return ['kind' => 'original', 'storage_key' => $relative, 'original_filename' => $originalFilename, 'checksum' => $checksum, 'mime_type' => strtolower((string) $info['mime']), 'byte_size' => (int) $size, 'width' => (int) ($info[0] ?? 0), 'height' => (int) ($info[1] ?? 0), 'visibility' => 'PRIVATE', 'metadata' => ['source_original' => true, 'original_filename' => $originalFilename, 'sizes' => []]];
    }

    private function adoptionPhase(int $attachmentId, string $phase, ?string $mediaId = null, array $details = []): void
    {
        $this->adoptionStage = $phase;
        $elapsed = $this->adoptionStartedAt === null ? null : max(0, (int) ((microtime(true) - $this->adoptionStartedAt) * 1000));
        $payload = array_merge(['stage' => $phase, 'attachment_id' => $attachmentId, 'media_id' => $mediaId, 'elapsed_ms' => $elapsed, 'at' => gmdate('c')], $details);
        if (function_exists('do_action')) {
            try { do_action('nhk_v3_media_adoption_phase', $phase, $attachmentId, $mediaId); } catch (\Throwable) { }
            try { do_action('nhk_v3_media_adoption_trace', $payload); } catch (\Throwable) { }
        }
        if (function_exists('error_log')) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            error_log('[nhk.media.adoption] ' . (is_string($encoded) ? $encoded : $phase));
        }
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
        $canonicalPath = (new \NHK\Core\Application\Media\PublicMediaAssetUrlResolver())->path(is_string($asset->metadata['canonical_filename'] ?? null) ? $asset->metadata['canonical_filename'] : basename($asset->storageKey));
        $url = function_exists('home_url') ? (string) home_url($canonicalPath) : $canonicalPath;
        if ($url === '') throw new \RuntimeException('WORDPRESS_MEDIA_ATTACHMENT_UNAVAILABLE');
        $width = is_array($src) ? (int) ($src[1] ?? 0) : (int) ($asset->width ?? 0);
        $height = is_array($src) ? (int) ($src[2] ?? 0) : (int) ($asset->height ?? 0);
        return ['attachment_id' => $attachmentId, 'url' => $url, 'src' => $url, 'srcset' => $url . ' ' . (int) ($asset->width ?? $width) . 'w', 'sizes' => function_exists('wp_get_attachment_image_sizes') ? (string) wp_get_attachment_image_sizes($attachmentId, 'large') : '', 'width' => (int) ($asset->width ?? $width), 'height' => (int) ($asset->height ?? $height), 'alt' => $alt];
    }

    private function assertAttachment(int $attachmentId): void
    {
        if ($attachmentId < 1) throw new \RuntimeException('WORDPRESS_MEDIA_ATTACHMENT_UNAVAILABLE');
        if (!function_exists('get_post')) return;
        $post = get_post($attachmentId);
        if (!is_object($post) || (string) ($post->post_type ?? '') !== 'attachment') throw new \RuntimeException('WORDPRESS_MEDIA_ATTACHMENT_UNAVAILABLE');
    }

    private function renderImage(int $attachmentId, string $alt, string $anchor = ''): string
    {
        if (function_exists('wp_get_attachment_image')) {
            $html = (string) wp_get_attachment_image($attachmentId, 'large', false, ['alt' => $alt, 'loading' => 'lazy']);
            $mediaId = $this->mediaIdForAttachment($attachmentId);
            $asset = $mediaId !== null ? ($this->assets->listByMediaId($mediaId)[0] ?? null) : null;
            if ($asset instanceof MediaAsset) {
                $url = (new \NHK\Core\Application\Media\PublicMediaAssetUrlResolver())->path(is_string($asset->metadata['canonical_filename'] ?? null) ? $asset->metadata['canonical_filename'] : basename($asset->storageKey));
                $absolute = function_exists('home_url') ? home_url($url) : $url;
                $html = (string) preg_replace('/\ssrc="[^"]*"/i', ' src="' . esc_url($absolute) . '"', $html);
                $html = (string) preg_replace('/\ssrcset="[^"]*"/i', ' srcset="' . esc_url($absolute) . ' ' . (int) ($asset->width ?? 0) . 'w"', $html);
            }
            if ($anchor !== '') {
                $safeAnchor = function_exists('esc_attr') ? esc_attr($anchor) : htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8');
                $html = (string) preg_replace('/<img\b/i', '<img id="' . $safeAnchor . '"', $html, 1);
            }
            return $html;
        }
        return '';
    }

    private function managedBlock(int $attachmentId, string $image, string $anchor = ''): string
    {
        $anchorJson = $anchor !== '' ? ',"anchor":"' . addslashes($anchor) . '"' : '';
        return '<!-- wp:image {"id":' . $attachmentId . ',"sizeSlug":"large","linkDestination":"none","className":"nhk-managed-inline-primary"' . $anchorJson . '} -->' . $image . '<!-- /wp:image -->';
    }

    private function replaceManagedBlock(string $content, string $image, int $attachmentId, string $anchor = ''): string
    {
        $replacement = $this->managedBlock($attachmentId, $image, $anchor);
        $updated = preg_replace('/<!-- wp:image\b[^>]*nhk-managed-inline-primary[^>]*-->.*?<!-- \/wp:image -->/is', $replacement, $content, 1);
        return is_string($updated) ? $updated : $content;
    }

    private function removeManagedBlock(string $content): string
    {
        $updated = preg_replace('/\s*<!-- wp:image\b[^>]*nhk-managed-inline-primary[^>]*-->.*?<!-- \/wp:image -->\s*/is', "\n", $content, 1);
        return is_string($updated) ? trim($updated) : $content;
    }

    /** @param list<int> $attachmentIds */
    private function removeMappedInlineImages(string $content, array $attachmentIds): string
    {
        foreach (array_values(array_filter(array_map('intval', $attachmentIds), static fn (int $id): bool => $id > 0)) as $attachmentId) {
            $patterns = [
                '/\s*<!-- wp:image\b[^>]*"id"\s*:\s*' . $attachmentId . '[^>]*-->.*?<!-- \/wp:image -->\s*/is',
                '/\s*<figure\b[^>]*>.*?(?:wp-image-' . $attachmentId . '|data-id=["\']' . $attachmentId . '["\']).*?<\/figure>\s*/is',
                '/\s*<img\b[^>]*(?:wp-image-' . $attachmentId . '|data-id=["\']' . $attachmentId . '["\'])[^>]*>\s*/is',
            ];
            foreach ($patterns as $pattern) {
                $updated = preg_replace($pattern, "\n", $content, 1);
                if (is_string($updated)) $content = $updated;
            }
        }
        return trim($content);
    }

    /** @param list<int|string> $attachmentIds @param list<int|string> $keep */
    private function removeMappedInlineImagesExcept(string $content, array $attachmentIds, array $keep): string
    {
        $keep = array_values(array_unique(array_filter(array_map('intval', $keep), static fn (int $id): bool => $id > 0)));
        $remove = array_values(array_filter(array_map('intval', $attachmentIds), static fn (int $id): bool => $id > 0 && !in_array($id, $keep, true)));
        return $this->removeMappedInlineImages($content, $remove);
    }

    private function removeInlineImagesExcept(string $content, int $attachmentId): string
    {
        $token = '(?:"id"\s*:\s*|wp-image-|data-id=["\'])' . preg_quote((string) $attachmentId, '/') . '(?:["\']|\b)';
        $updated = preg_replace_callback('/<!-- wp:image\b.*?<!-- \/wp:image -->/is', static fn (array $match): string => preg_match('/' . $token . '/i', $match[0]) === 1 ? $match[0] : '', $content);
        $content = is_string($updated) ? $updated : $content;
        $updated = preg_replace_callback('/<figure\b[^>]*>.*?<\/figure>/is', static fn (array $match): string => preg_match('/' . $token . '/i', $match[0]) === 1 ? $match[0] : '', $content);
        $content = is_string($updated) ? $updated : $content;
        $updated = preg_replace_callback('/<img\b[^>]*>/i', static fn (array $match): string => preg_match('/' . $token . '/i', $match[0]) === 1 ? $match[0] : '', $content);
        return is_string($updated) ? trim($updated) : trim($content);
    }

    private function replaceFirstImageBlock(string $content, string $image, int $attachmentId, string $anchor = ''): string
    {
        $replacement = $this->managedBlock($attachmentId, $image, $anchor);
        $updated = preg_replace('/<!-- wp:image\b.*?<!-- \/wp:image -->/is', $replacement, $content, 1);
        return is_string($updated) ? $updated : $content;
    }

    private function ensurePlacementAnchor(string $content, int $attachmentId, string $anchor): string
    {
        $safeAnchor = function_exists('esc_attr') ? esc_attr($anchor) : htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8');
        $pattern = '/<img\b(?=[^>]*(?:wp-image-' . preg_quote((string) $attachmentId, '/') . '|data-id=["\']' . preg_quote((string) $attachmentId, '/') . '["\']))[^>]*>/i';
        $updated = preg_replace_callback($pattern, static function (array $match) use ($safeAnchor): string {
            if (preg_match('/\bid=["\'][^"\']+["\']/i', $match[0]) === 1) return (string) preg_replace('/\bid=["\'][^"\']+["\']/i', 'id="' . $safeAnchor . '"', $match[0], 1);
            return (string) preg_replace('/<img\b/i', '<img id="' . $safeAnchor . '"', $match[0], 1);
        }, $content, 1);
        return is_string($updated) ? $updated : $content;
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

    /** @param array<string,mixed> $current @param array<string,mixed> $slot */
    private function managedFeaturedMediaMatches(array $current, array $slot): bool
    {
        $persistedMediaId = trim((string) ($slot['persisted_media_id'] ?? ''));
        return $persistedMediaId !== '' && $persistedMediaId === trim((string) ($current['featured_media_id'] ?? ''));
    }

    /** @param array<string,mixed> $slot */
    private function managedInlineMediaMatches(int $attachmentId, array $slot, string $content): bool
    {
        $persistedMediaId = trim((string) ($slot['persisted_media_id'] ?? ''));
        if ($persistedMediaId === '' || $this->mediaIdForAttachment($attachmentId) !== $persistedMediaId) return false;
        $anchor = trim((string) ($slot['placement_anchor'] ?? ''));
        return $anchor === '' || $this->managedInlinePlacementAnchor($content) === $anchor;
    }

    private function managedInlinePlacementAnchor(string $content): string
    {
        if (preg_match('/<!-- wp:image\b[^>]*nhk-managed-inline-primary[^>]*"anchor"\s*:\s*"([^"]+)"[^>]*-->/i', $content, $match) === 1) return (string) $match[1];
        return '';
    }

    private function hasMappedInlineMedia(array $inlineIds, string $expectedMediaId): bool
    {
        foreach ($inlineIds as $id) {
            if ($this->mediaIdForAttachment((int) $id) === trim($expectedMediaId)) return true;
        }
        return false;
    }

    private function mediaIdForAttachment(int $attachmentId): ?string
    {
        if ($attachmentId < 1) return null;
        $value = $this->database->get_var($this->database->prepare("SELECT media_uuid FROM {$this->table} WHERE attachment_id=%d LIMIT 1", $attachmentId));
        $decoded = $this->decodeUuidValue($value);
        if ($decoded !== null) return $decoded;
        foreach ($this->media->list() as $media) {
            if (!$media instanceof Media || !$media->active) continue;
            foreach ($this->assets->listByMediaId($media->canonicalId) as $asset) {
                if ((int) ($asset->metadata['wordpress_attachment_id'] ?? 0) === $attachmentId) return $media->canonicalId;
            }
        }
        return null;
    }

    private function decodeUuidValue(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        if (strlen($value) === 16) return UuidCodec::fromBinary($value);
        if (preg_match('/^[0-9a-f]{32}$/i', $value) === 1) return UuidCodec::fromBinary(hex2bin($value));
        return null;
    }

    /** @return array{status:string,media_id:?string} */
    public function attachmentMapping(int $attachmentId): array
    {
        $mediaId = $this->mediaIdForAttachment($attachmentId);
        if ($mediaId === null) return ['status' => 'UNMAPPED', 'media_id' => null];
        $media = $this->media->findByCanonicalId($mediaId);
        if (!$media instanceof Media || !$media->active) return ['status' => 'INCONSISTENT', 'media_id' => $mediaId];
        return ['status' => 'MAPPED', 'media_id' => $mediaId];
    }

    /**
     * Reconciles a durable bridge row back into the canonical MediaAsset
     * metadata. This is a repair of an existing identity, never an upload or
     * Media creation path.
     */
    public function reconcileAttachmentBinding(string $mediaId, int $attachmentId): bool
    {
        if (!UuidCodec::isValid($mediaId) || $attachmentId < 1) return false;
        $row = $this->database->get_row($this->database->prepare("SELECT media_uuid,asset_uuid,storage_key FROM {$this->table} WHERE attachment_id=%d LIMIT 1", $attachmentId), ARRAY_A);
        if (!is_array($row) || $this->decodeUuidValue($row['media_uuid'] ?? null) !== $mediaId) return false;
        $assetId = $this->decodeUuidValue($row['asset_uuid'] ?? null);
        $asset = $assetId !== null ? $this->assets->findByAssetId($assetId) : null;
        if (!$asset instanceof MediaAsset || $asset->mediaId !== $mediaId) return false;
        if ((int) ($asset->metadata['wordpress_attachment_id'] ?? 0) === $attachmentId) return true;
        $metadata = $asset->metadata;
        $metadata['wordpress_attachment_id'] = $attachmentId;
        $updated = new MediaAsset($asset->assetId, $asset->mediaId, $asset->kind, $asset->storageKey, $asset->checksum, $asset->mimeType, $asset->byteSize, $asset->width, $asset->height, $asset->visibility, $metadata);
        $this->assets->update($updated, 1);
        return (int) (($this->assets->findByAssetId($asset->assetId)?->metadata['wordpress_attachment_id'] ?? 0)) === $attachmentId;
    }

    public function attachmentIdForMediaReference(string $mediaId): int
    {
        return UuidCodec::isValid($mediaId) ? $this->attachmentIdForMedia($mediaId) : 0;
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

}
