<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{ArticleMediaBlueprintRepository, MediaAssetRepository, MediaRepository, MediaUsageRepository, MediaUsageUpdater, MutableMediaUsageRepository, WordPressArticleMediaAdapter};
use NHK\Core\Domain\Media\{Media, MediaSeoBlueprint, MediaSeoStateRegistry, MediaUsageRoleRegistry};

final class ArticleMediaCoordinator
{
    public function __construct(
        private MediaService $mediaService,
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private MediaUsageRepository $usages,
        private ArticleMediaBlueprintRepository $blueprints,
        private ?int $blogId = null,
        private ?WordPressArticleMediaAdapter $wordpress = null,
    ) {}

    /** @param array<string,mixed> $context @param array<string,string> $selectedMediaBySlot @param list<string> $supportingMediaIds */
    public function ensureForPost(int $postId, array $context = [], array $selectedMediaBySlot = [], array $supportingMediaIds = []): ArticleMediaResult
    {
        if ($postId < 1) throw new \InvalidArgumentException('WordPress Post ID must be positive.');
        $endpointKey = $this->endpointKey($postId);
        $slotMedia = [];
        $slots = [];
        $diagnostics = [];
        $editorial = $this->wordpress?->read($postId);
        if (is_array($editorial)) {
            $editorialFeatured = trim((string) ($editorial['featured_media_id'] ?? ''));
            if (($selectedMediaBySlot[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '') === '' && $editorialFeatured !== '') $selectedMediaBySlot[MediaUsageRoleRegistry::FEATURED_PRIMARY] = $editorialFeatured;
            if (($selectedMediaBySlot[MediaUsageRoleRegistry::INLINE_PRIMARY] ?? '') === '') {
                $featured = (string) ($selectedMediaBySlot[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '');
                foreach (($editorial['inline_media_ids'] ?? []) as $editorialMediaId) {
                    $editorialMediaId = trim((string) $editorialMediaId);
                    if ($editorialMediaId !== '' && $editorialMediaId !== $featured) { $selectedMediaBySlot[MediaUsageRoleRegistry::INLINE_PRIMARY] = $editorialMediaId; break; }
                }
            }
            foreach (($editorial['unmapped_attachment_ids'] ?? []) as $attachmentId) $diagnostics[] = ['code' => 'WORDPRESS_ATTACHMENT_UNMAPPED', 'attachment_id' => (int) $attachmentId];
        }
        foreach (MediaUsageRoleRegistry::mandatoryArticleRoles() as $slot) {
            $blueprint = MediaSeoBlueprint::forPost($postId, $slot, $context, MediaSeoStateRegistry::PLACEHOLDER);
            $existing = $this->existingSlotMedia($endpointKey, $slot);
            $candidateId = trim((string) ($selectedMediaBySlot[$slot] ?? ''));
            $candidate = $candidateId !== '' ? $this->usableMedia($candidateId, $blueprint) : null;
            if ($candidate === null && $existing !== null && !in_array($existing->canonicalId, array_values($slotMedia), true)) $candidate = $this->usableMedia($existing->canonicalId, $blueprint);
            if ($candidate === null) $candidate = $this->findReusable($blueprint, array_values($slotMedia));
            if ($candidate === null) $candidate = $this->placeholder($slot);
            $this->reconcileUsage($endpointKey, $slot, $candidate->canonicalId, $blueprint);
            $state = $candidate->isSystemPlaceholder() ? ($slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? MediaSeoStateRegistry::INCOMPLETE_FEATURED : MediaSeoStateRegistry::INCOMPLETE_INLINE) : MediaSeoStateRegistry::COMPLETE;
            if ($candidate->isSystemPlaceholder()) $diagnostics[] = ['code' => $slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? 'ARTICLE_MEDIA_FEATURED_MISSING' : 'ARTICLE_MEDIA_INLINE_MISSING', 'slot' => $slot, 'media_id' => $candidate->canonicalId];
            $blueprint = MediaSeoBlueprint::forPost($postId, $slot, $context, $state);
            $this->blueprints->save($blueprint);
            $slotMedia[$slot] = $candidate->canonicalId;
            $slots[$slot] = ['media_id' => $candidate->canonicalId, 'placeholder' => $candidate->isSystemPlaceholder(), 'state' => $state, 'blueprint' => $blueprint->toArray()];
        }
        foreach ($supportingMediaIds as $index => $mediaId) {
            $candidate = $this->usableMedia((string) $mediaId, MediaSeoBlueprint::forPost($postId, MediaUsageRoleRegistry::INLINE_PRIMARY, $context));
            if ($candidate !== null) $this->mediaService->addUsage($candidate->canonicalId, 'wp_post', $endpointKey, MediaUsageRoleRegistry::INLINE_SUPPORTING, $index);
        }
        $desiredUsages = [];
        foreach ($slotMedia as $role => $mediaId) $desiredUsages[] = ['role' => $role, 'media_id' => $mediaId, 'sort_order' => 0, 'alt_text' => (string) ($slots[$role]['blueprint']['planned_alt_intent'] ?? ''), 'keyword_groups' => (array) ($slots[$role]['blueprint']['keyword_groups'] ?? [])];
        foreach ($supportingMediaIds as $index => $mediaId) $desiredUsages[] = ['role' => MediaUsageRoleRegistry::INLINE_SUPPORTING, 'media_id' => (string) $mediaId, 'sort_order' => $index];
        $usagePlan = (new MediaUsageReconciler())->plan('wp_post', $endpointKey, $this->usages->listByEndpoint('wp_post', $endpointKey), $desiredUsages);
        $diagnostics[] = ['code' => 'MEDIA_USAGE_RECONCILIATION', 'status' => $usagePlan['status'], 'actions' => $usagePlan['actions']];
        $state = array_filter($slots, static fn (array $slot): bool => $slot['placeholder']) !== [] ? MediaSeoStateRegistry::PLACEHOLDER : (in_array('MEDIA_LOW_RESOLUTION', array_column($diagnostics, 'code'), true) ? MediaSeoStateRegistry::LOW_RESOLUTION : MediaSeoStateRegistry::COMPLETE);
        $result = new ArticleMediaResult($postId, $endpointKey, $state, $slotMedia, $slots, $diagnostics, is_array($editorial) ? (string) ($editorial['state_token'] ?? '') : '');
        if ($this->wordpress !== null) {
            $payload = $result->toArray();
            $payload['force_inline_reconcile'] = ($context['force_inline_reconcile'] ?? false) === true;
            if (is_array($editorial) && isset($editorial['state_token'])) $payload['editorial_state_token'] = (string) $editorial['state_token'];
            $readback = $this->wordpress->synchronize($postId, $payload);
            $actualFeatured = trim((string) ($readback['featured_media_id'] ?? ''));
            if ($actualFeatured !== '' && $actualFeatured !== ($slotMedia[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '')) {
                $blueprint = $this->blueprints->findByPostAndSlot($postId, MediaUsageRoleRegistry::FEATURED_PRIMARY) ?? MediaSeoBlueprint::forPost($postId, MediaUsageRoleRegistry::FEATURED_PRIMARY, $context, MediaSeoStateRegistry::COMPLETE);
                $this->reconcileUsage($endpointKey, MediaUsageRoleRegistry::FEATURED_PRIMARY, $actualFeatured, $blueprint);
                $slotMedia[MediaUsageRoleRegistry::FEATURED_PRIMARY] = $actualFeatured;
                $slots[MediaUsageRoleRegistry::FEATURED_PRIMARY]['media_id'] = $actualFeatured;
            }
            $actualInline = '';
            foreach (($readback['inline_media_ids'] ?? []) as $actualMediaId) {
                $actualMediaId = trim((string) $actualMediaId);
                if ($actualMediaId !== '' && $actualMediaId !== ($slotMedia[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '')) { $actualInline = $actualMediaId; break; }
            }
            if ($actualInline !== '' && $actualInline !== ($slotMedia[MediaUsageRoleRegistry::INLINE_PRIMARY] ?? '')) {
                $blueprint = $this->blueprints->findByPostAndSlot($postId, MediaUsageRoleRegistry::INLINE_PRIMARY) ?? MediaSeoBlueprint::forPost($postId, MediaUsageRoleRegistry::INLINE_PRIMARY, $context, MediaSeoStateRegistry::COMPLETE);
                $this->reconcileUsage($endpointKey, MediaUsageRoleRegistry::INLINE_PRIMARY, $actualInline, $blueprint);
                $slotMedia[MediaUsageRoleRegistry::INLINE_PRIMARY] = $actualInline;
                $slots[MediaUsageRoleRegistry::INLINE_PRIMARY]['media_id'] = $actualInline;
            }
            $state = array_filter($slots, static fn (array $slot): bool => $slot['placeholder']) !== [] ? MediaSeoStateRegistry::PLACEHOLDER : (in_array('MEDIA_LOW_RESOLUTION', array_column($diagnostics, 'code'), true) ? MediaSeoStateRegistry::LOW_RESOLUTION : MediaSeoStateRegistry::COMPLETE);
            $result = new ArticleMediaResult($postId, $endpointKey, $state, $slotMedia, $slots, $diagnostics, (string) ($readback['state_token'] ?? ''));
        }
        return $result;
    }

    /** Read-only preview for preflight/diagnostics; it never creates placeholders or usages. */
    public function diagnoseForPost(int $postId, array $context = []): ArticleMediaResult
    {
        $endpointKey = $this->endpointKey($postId);
        $slots = []; $slotMedia = []; $diagnostics = [];
        foreach (MediaUsageRoleRegistry::mandatoryArticleRoles() as $slot) {
            $existing = $this->existingSlotMedia($endpointKey, $slot);
            $placeholder = $existing?->isSystemPlaceholder() ?? true;
            $id = $existing?->canonicalId ?? '';
            $slotMedia[$slot] = $id;
            $slots[$slot] = ['media_id' => $id, 'placeholder' => $placeholder, 'state' => $placeholder ? ($slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? MediaSeoStateRegistry::INCOMPLETE_FEATURED : MediaSeoStateRegistry::INCOMPLETE_INLINE) : MediaSeoStateRegistry::COMPLETE, 'blueprint' => ($this->blueprints->findByPostAndSlot($postId, $slot) ?? MediaSeoBlueprint::forPost($postId, $slot, $context))->toArray()];
            if ($placeholder) $diagnostics[] = ['code' => $slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? 'ARTICLE_MEDIA_FEATURED_MISSING' : 'ARTICLE_MEDIA_INLINE_MISSING', 'slot' => $slot];
        }
        return new ArticleMediaResult($postId, $endpointKey, $diagnostics === [] ? MediaSeoStateRegistry::COMPLETE : MediaSeoStateRegistry::PLACEHOLDER, $slotMedia, $slots, $diagnostics);
    }

    private function endpointKey(int $postId): string
    {
        $blogId = $this->blogId ?? (function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1);
        return max(1, $blogId) . ':' . $postId;
    }

    private function existingSlotMedia(string $endpointKey, string $slot): ?Media
    {
        foreach ($this->usages->listByEndpoint('wp_post', $endpointKey, $slot) as $usage) {
            $media = $this->media->findByCanonicalId($usage->mediaId);
            if ($media !== null) return $media;
        }
        return null;
    }

    private function usableMedia(string $id, MediaSeoBlueprint $blueprint): ?Media
    {
        $media = $this->media->findByCanonicalId($id);
        if ($media === null || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return null;
        return $this->assets->listByMediaId($media->canonicalId) === [] ? null : $media;
    }

    /** @param list<string> $used */
    private function findReusable(MediaSeoBlueprint $blueprint, array $used): ?Media
    {
        $best = null; $bestScore = -1;
        foreach ($this->media->list() as $media) {
            if (in_array($media->canonicalId, $used, true)) continue;
            $candidate = $this->usableMedia($media->canonicalId, $blueprint);
            if ($candidate === null) continue;
            $score = 1;
            $subject = strtolower((string) ($blueprint->subjectContext['subject'] ?? ''));
            if ($subject !== '' && str_contains(strtolower($candidate->canonicalName), $subject)) $score += 4;
            if (($blueprint->preferredView ?? '') !== '' && ($candidate->provenance['detail_type'] ?? '') === $blueprint->preferredView) $score += 3;
            foreach ($this->assets->listByMediaId($candidate->canonicalId) as $asset) if (($asset->width ?? 0) >= $blueprint->minimumWidth) $score += 2;
            if ($score > $bestScore || ($score === $bestScore && ($best === null || $candidate->stableKey < $best->stableKey))) { $best = $candidate; $bestScore = $score; }
        }
        return $best;
    }

    private function placeholder(string $slot): Media
    {
        $key = 'system:placeholder:' . $slot;
        $name = $slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? 'System placeholder — featured image' : 'System placeholder — inline image';
        return $this->mediaService->create($key, $name, 'ready', ['system_role' => 'placeholder', 'slot' => $slot]);
    }

    private function reconcileUsage(string $endpointKey, string $slot, string $mediaId, \NHK\Core\Domain\Media\MediaSeoBlueprint $blueprint): void
    {
        $existing = $this->usages->listByEndpoint('wp_post', $endpointKey, $slot);
        foreach ($existing as $usage) {
            if ($usage->mediaId !== $mediaId) continue;
            $candidate = new \NHK\Core\Domain\Media\MediaUsage($usage->usageId, $mediaId, 'wp_post', $endpointKey, $slot, 0, $blueprint->plannedAltIntent, '', $blueprint->keywordGroups);
            if ($usage->sortOrder === $candidate->sortOrder && $usage->altText === $candidate->altText && $usage->caption === $candidate->caption && $usage->keywordGroups === $candidate->keywordGroups) return;
            if ($this->usages instanceof MediaUsageUpdater) {
                $this->usages->update($candidate);
                return;
            }
            throw new \RuntimeException('ARTICLE_MEDIA_USAGE_UPDATE_UNAVAILABLE');
        }
        if ($existing !== []) {
            $current = $existing[0];
            $candidate = new \NHK\Core\Domain\Media\MediaUsage($current->usageId, $mediaId, 'wp_post', $endpointKey, $slot, 0, $blueprint->plannedAltIntent, '', $blueprint->keywordGroups);
            if ($this->usages instanceof MediaUsageUpdater) {
                $this->usages->update($candidate);
                return;
            }
            if ($this->usages instanceof MutableMediaUsageRepository) throw new \RuntimeException('ARTICLE_MEDIA_USAGE_REPLACEMENT_UNAVAILABLE');
        }
        $this->mediaService->addUsage($mediaId, 'wp_post', $endpointKey, $slot, 0, $blueprint->plannedAltIntent, '', $blueprint->keywordGroups);
    }
}
