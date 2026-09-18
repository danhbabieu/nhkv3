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
        // Capture/media adapters may carry the resolved subject only inside
        // the canonical resolution packet. Normalize that packet once at the
        // boundary so every downstream blueprint receives the same exact
        // subject context; an explicit empty value must not produce an
        // invalid blueprint or silently fall back to a generic article label.
        $context = $this->normalizeSubjectContext($context);
        $endpointKey = $this->endpointKey($postId);
        $slotMedia = [];
        $slots = [];
        $diagnostics = [];
        $captureOwnedMediaIds = array_values(array_unique(array_filter(array_map('strval', (array) ($context['capture_owned_media_ids'] ?? [])), static fn (string $id): bool => trim($id) !== '')));
        $subjectIds = array_values(array_filter(array_map('strval', (array) ($context['subject_ids'] ?? [])), static fn (string $id): bool => trim($id) !== ''));
        $subjectScopeLocked = $subjectIds !== [] && (($context['subject_scope_locked'] ?? true) === true);
        $captureMediaContext = array_key_exists('capture_has_physical_assets', $context) || array_key_exists('capture_id', $context);
        $contentIntent = strtoupper(trim((string) ($context['content_intent']['intent'] ?? $context['content_intent'] ?? '')));
        $singleRealImage = $contentIntent === 'IMAGE_ARTICLE'
            && count($captureOwnedMediaIds) === 1
            && ($context['single_real_image_exception'] ?? false) === true
            && $this->mediaIsReadyAndPublic($captureOwnedMediaIds[0]);
        // Preserve the existing explicit Article selection contract when the
        // caller is reconciling an Article outside the Capture path. The
        // bounded Capture exception above remains the only way committed
        // Capture IDs can collapse both mandatory roles.
        $explicitSingleMediaSelection = $captureOwnedMediaIds === []
            && $contentIntent === 'IMAGE_ARTICLE'
            && ($context['single_real_image_exception'] ?? false) === true
            && trim((string) ($selectedMediaBySlot[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '')) !== ''
            && trim((string) ($selectedMediaBySlot[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '')) === trim((string) ($selectedMediaBySlot[MediaUsageRoleRegistry::INLINE_PRIMARY] ?? ''))
            && $this->mediaIsReadyAndPublic(trim((string) ($selectedMediaBySlot[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '')));
        $singleRealImage = $singleRealImage || $explicitSingleMediaSelection;
        $enforceDistinctMandatoryMedia = $contentIntent !== '' && !$singleRealImage;
        $allowHistoricalReuse = !$captureMediaContext
            ? (($context['allow_unscoped_reuse'] ?? true) === true)
            : ($subjectScopeLocked && (($context['allow_scoped_reuse'] ?? false) === true || ($context['allow_unscoped_reuse'] ?? false) === true));
        $allowHistoricalSubjectReuse = !$captureMediaContext
            ? $allowHistoricalReuse
            : ($subjectScopeLocked && (($context['allow_scoped_reuse'] ?? false) === true || ($context['allow_unscoped_reuse'] ?? false) === true));
        $editorial = $this->wordpress?->read($postId);
        if (is_array($editorial) && $allowHistoricalReuse) {
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
        if (!$allowHistoricalReuse && $captureMediaContext) $selectedMediaBySlot = [];
        if ($captureOwnedMediaIds !== []) {
            if (($selectedMediaBySlot[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '') === '') $selectedMediaBySlot[MediaUsageRoleRegistry::FEATURED_PRIMARY] = $captureOwnedMediaIds[0];
            if (($selectedMediaBySlot[MediaUsageRoleRegistry::INLINE_PRIMARY] ?? '') === '' && (count($captureOwnedMediaIds) > 1 || $singleRealImage)) {
                $selectedMediaBySlot[MediaUsageRoleRegistry::INLINE_PRIMARY] = $captureOwnedMediaIds[count($captureOwnedMediaIds) > 1 ? 1 : 0];
            }
        }
        foreach ($captureOwnedMediaIds as $captureOwnedMediaId) {
            if (!$this->mediaIsReadyAndPublic($captureOwnedMediaId)) {
                $diagnostics[] = ['code' => 'MEDIAUSAGE_INCOMPLETE', 'media_id' => $captureOwnedMediaId];
            }
        }
        foreach (MediaUsageRoleRegistry::mandatoryArticleRoles() as $slot) {
            $blueprint = MediaSeoBlueprint::forPost($postId, $slot, $context, MediaSeoStateRegistry::PLACEHOLDER);
            $existing = $this->existingSlotMedia($endpointKey, $slot);
            $candidateId = trim((string) ($selectedMediaBySlot[$slot] ?? ''));
            if ($slot === MediaUsageRoleRegistry::INLINE_PRIMARY && $enforceDistinctMandatoryMedia && $candidateId !== '' && $candidateId === ($slotMedia[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '')) $candidateId = '';
            $candidateIsCaptureOwned = $candidateId !== '' && in_array($candidateId, $captureOwnedMediaIds, true);
            $candidateRequiresScope = $subjectScopeLocked && !($captureMediaContext && $candidateIsCaptureOwned);
            $candidate = $candidateId !== '' ? $this->usableMedia($candidateId, $blueprint, $candidateRequiresScope) : null;
            if ($candidate === null && $allowHistoricalSubjectReuse && $existing !== null && !in_array($existing->canonicalId, array_values($slotMedia), true)) $candidate = $this->usableMedia($existing->canonicalId, $blueprint, $subjectScopeLocked);
            if ($candidate === null && $allowHistoricalSubjectReuse) $candidate = $this->findReusable($blueprint, array_values($slotMedia), $subjectScopeLocked, !$enforceDistinctMandatoryMedia);
            if ($candidate === null) $candidate = $this->placeholder($slot);
            $usage = $this->reconcileUsage($endpointKey, $slot, $candidate->canonicalId, $blueprint, 'article:' . $endpointKey . ':' . $slot);
            $state = $candidate->isSystemPlaceholder() ? ($slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? MediaSeoStateRegistry::INCOMPLETE_FEATURED : MediaSeoStateRegistry::INCOMPLETE_INLINE) : MediaSeoStateRegistry::COMPLETE;
            if ($candidate->isSystemPlaceholder()) $diagnostics[] = ['code' => $slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? 'ARTICLE_MEDIA_FEATURED_MISSING' : 'ARTICLE_MEDIA_INLINE_MISSING', 'slot' => $slot, 'media_id' => $candidate->canonicalId];
            $blueprint = MediaSeoBlueprint::forPost($postId, $slot, $context, $state);
            $this->blueprints->save($blueprint);
            $slotMedia[$slot] = $candidate->canonicalId;
            $slots[$slot] = ['media_id' => $candidate->canonicalId, 'placeholder' => $candidate->isSystemPlaceholder(), 'state' => $state, 'placement_key' => $usage->placementKey, 'placement_anchor' => $usage->placementAnchor(), 'blueprint' => $blueprint->toArray()];
        }
        $supportingPlacements = $this->normalizeSupportingPlacements($supportingMediaIds);
        foreach ($supportingPlacements as $placement) {
            $index = $placement['sort_order'];
            $supportingId = $placement['media_id'];
            $supportingRequiresScope = $subjectScopeLocked && !($captureMediaContext && in_array($supportingId, $captureOwnedMediaIds, true));
            $candidate = $this->usableMedia($supportingId, MediaSeoBlueprint::forPost($postId, MediaUsageRoleRegistry::INLINE_PRIMARY, $context), $supportingRequiresScope);
            if ($candidate !== null) $this->mediaService->addUsage($candidate->canonicalId, 'wp_post', $endpointKey, MediaUsageRoleRegistry::INLINE_SUPPORTING, $index, '', '', [], '', $placement['placement_key']);
        }
        $desiredUsages = [];
        foreach ($slotMedia as $role => $mediaId) $desiredUsages[] = ['role' => $role, 'media_id' => $mediaId, 'sort_order' => 0, 'placement_key' => (string) ($slots[$role]['placement_key'] ?? ''), 'alt_text' => (string) ($slots[$role]['blueprint']['planned_alt_intent'] ?? ''), 'title' => (string) ($slots[$role]['blueprint']['planned_title'] ?? ''), 'keyword_groups' => (array) ($slots[$role]['blueprint']['keyword_groups'] ?? [])];
        foreach ($supportingPlacements as $placement) $desiredUsages[] = ['role' => MediaUsageRoleRegistry::INLINE_SUPPORTING, 'media_id' => $placement['media_id'], 'sort_order' => $placement['sort_order'], 'placement_key' => $placement['placement_key']];
        $usagePlan = (new MediaUsageReconciler())->plan('wp_post', $endpointKey, $this->usages->listByEndpoint('wp_post', $endpointKey), $desiredUsages);
        $diagnostics[] = ['code' => 'MEDIA_USAGE_RECONCILIATION', 'status' => $usagePlan['status'], 'actions' => $usagePlan['actions']];
        $state = array_filter($slots, static fn (array $slot): bool => $slot['placeholder']) !== [] ? MediaSeoStateRegistry::PLACEHOLDER : (in_array('MEDIA_LOW_RESOLUTION', array_column($diagnostics, 'code'), true) ? MediaSeoStateRegistry::LOW_RESOLUTION : MediaSeoStateRegistry::COMPLETE);
        $guidance = $this->guidance($slots, $context);
        $canonicalReadback = $this->canonicalReadback($endpointKey, $slots, $diagnostics);
        $result = new ArticleMediaResult($postId, $endpointKey, $state, $slotMedia, $slots, $diagnostics, is_array($editorial) ? (string) ($editorial['state_token'] ?? '') : '', $guidance, $canonicalReadback);
        if ($this->wordpress !== null) {
            $payload = $result->toArray();
            $payload['force_inline_reconcile'] = ($context['force_inline_reconcile'] ?? false) === true;
            if (is_array($editorial) && isset($editorial['state_token'])) $payload['editorial_state_token'] = (string) $editorial['state_token'];
            $readback = $this->wordpress->synchronize($postId, $payload);
            $actualFeatured = trim((string) ($readback['featured_media_id'] ?? ''));
            if ($actualFeatured !== '' && $actualFeatured !== ($slotMedia[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '')) {
                $blueprint = $this->blueprints->findByPostAndSlot($postId, MediaUsageRoleRegistry::FEATURED_PRIMARY) ?? MediaSeoBlueprint::forPost($postId, MediaUsageRoleRegistry::FEATURED_PRIMARY, $context, MediaSeoStateRegistry::COMPLETE);
                $actualRequiresScope = !($subjectScopeLocked && in_array($actualFeatured, $captureOwnedMediaIds, true));
                if ($this->usableMedia($actualFeatured, $blueprint, $actualRequiresScope) !== null) {
                    $this->reconcileUsage($endpointKey, MediaUsageRoleRegistry::FEATURED_PRIMARY, $actualFeatured, $blueprint);
                    $slotMedia[MediaUsageRoleRegistry::FEATURED_PRIMARY] = $actualFeatured;
                    $slots[MediaUsageRoleRegistry::FEATURED_PRIMARY]['media_id'] = $actualFeatured;
                } else {
                    $diagnostics[] = ['code' => 'ARTICLE_MEDIA_FEATURED_MISSING', 'slot' => MediaUsageRoleRegistry::FEATURED_PRIMARY, 'reason' => 'STALE_WORDPRESS_USAGE_REJECTED', 'media_id' => $actualFeatured];
                }
            }
            $actualInline = '';
            foreach (($readback['inline_media_ids'] ?? []) as $actualMediaId) {
                $actualMediaId = trim((string) $actualMediaId);
                if ($actualMediaId !== '' && $actualMediaId !== ($slotMedia[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '')) { $actualInline = $actualMediaId; break; }
            }
            if ($actualInline !== '' && $actualInline !== ($slotMedia[MediaUsageRoleRegistry::INLINE_PRIMARY] ?? '')) {
                $blueprint = $this->blueprints->findByPostAndSlot($postId, MediaUsageRoleRegistry::INLINE_PRIMARY) ?? MediaSeoBlueprint::forPost($postId, MediaUsageRoleRegistry::INLINE_PRIMARY, $context, MediaSeoStateRegistry::COMPLETE);
                $actualRequiresScope = !($subjectScopeLocked && in_array($actualInline, $captureOwnedMediaIds, true));
                if ($this->usableMedia($actualInline, $blueprint, $actualRequiresScope) !== null) {
                    $this->reconcileUsage($endpointKey, MediaUsageRoleRegistry::INLINE_PRIMARY, $actualInline, $blueprint);
                    $slotMedia[MediaUsageRoleRegistry::INLINE_PRIMARY] = $actualInline;
                    $slots[MediaUsageRoleRegistry::INLINE_PRIMARY]['media_id'] = $actualInline;
                } else {
                    $diagnostics[] = ['code' => 'ARTICLE_MEDIA_INLINE_MISSING', 'slot' => MediaUsageRoleRegistry::INLINE_PRIMARY, 'reason' => 'STALE_WORDPRESS_USAGE_REJECTED', 'media_id' => $actualInline];
                }
            }
            $state = array_filter($slots, static fn (array $slot): bool => $slot['placeholder']) !== [] ? MediaSeoStateRegistry::PLACEHOLDER : (in_array('MEDIA_LOW_RESOLUTION', array_column($diagnostics, 'code'), true) ? MediaSeoStateRegistry::LOW_RESOLUTION : MediaSeoStateRegistry::COMPLETE);
            $canonicalReadback = $this->canonicalReadback($endpointKey, $slots, $diagnostics);
            $result = new ArticleMediaResult($postId, $endpointKey, $state, $slotMedia, $slots, $diagnostics, (string) ($readback['state_token'] ?? ''), $this->guidance($slots, $context), $canonicalReadback);
        }
        return $result;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function normalizeSubjectContext(array $context): array
    {
        $resolution = is_array($context['subject_resolution'] ?? null) ? $context['subject_resolution'] : [];
        $primary = is_array($resolution['primary'] ?? null) ? $resolution['primary'] : [];
        $subject = trim((string) ($context['subject'] ?? ''));
        $resolvedName = trim((string) ($primary['name'] ?? ''));
        if ($subject === '' && ($resolution['status'] ?? '') === 'resolved' && $resolvedName !== '') $context['subject'] = $resolvedName;

        $subjectContext = is_array($context['subject_context'] ?? null) ? $context['subject_context'] : [];
        if (trim((string) ($subjectContext['subject'] ?? '')) === '' && trim((string) ($context['subject'] ?? '')) !== '') $subjectContext['subject'] = (string) $context['subject'];
        $resolvedId = trim((string) ($primary['id'] ?? ''));
        $subjectIds = array_values(array_filter(array_map('strval', (array) ($context['subject_ids'] ?? [])), static fn (string $id): bool => trim($id) !== ''));
        if ($subjectIds === [] && ($resolution['status'] ?? '') === 'resolved' && $resolvedId !== '') $subjectIds = [$resolvedId];
        if ($subjectIds !== []) {
            $context['subject_ids'] = $subjectIds;
            $subjectContext['subject_ids'] = $subjectIds;
        }
        if ($subjectContext !== []) $context['subject_context'] = $subjectContext;
        return $context;
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
        return new ArticleMediaResult($postId, $endpointKey, $diagnostics === [] ? MediaSeoStateRegistry::COMPLETE : MediaSeoStateRegistry::PLACEHOLDER, $slotMedia, $slots, $diagnostics, '', $this->guidance($slots, $context));
    }

    private function endpointKey(int $postId): string
    {
        $blogId = $this->blogId ?? (function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1);
        return max(1, $blogId) . ':' . $postId;
    }

    private function existingSlotMedia(string $endpointKey, string $slot): ?Media
    {
        foreach ($this->usages->listByEndpoint('wp_post', $endpointKey, $slot) as $usage) {
            if ($usage->activeSlot === 'retired') continue;
            $media = $this->media->findByCanonicalId($usage->mediaId);
            if ($media !== null) return $media;
        }
        return null;
    }

    private function usableMedia(string $id, MediaSeoBlueprint $blueprint, bool $requireSubjectScope = false): ?Media
    {
        $media = $this->media->findByCanonicalId($id);
        if ($media === null || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return null;
        if ($requireSubjectScope && !$this->matchesSubjectScope($media, $blueprint, true)) return null;
        return $this->mediaIsReadyAndPublic($media->canonicalId) ? $media : null;
    }

    private function mediaIsReadyAndPublic(string $mediaId): bool
    {
        $media = $this->media->findByCanonicalId($mediaId);
        if ($media === null || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return false;
        foreach ($this->assets->listByMediaId($mediaId) as $asset) if ($asset->visibility === 'PUBLIC') return true;
        return false;
    }

    /** @param array<string,array<string,mixed>> $slots @param list<array<string,mixed>> $diagnostics @return array<string,mixed> */
    private function canonicalReadback(string $endpointKey, array $slots, array $diagnostics): array
    {
        $requiredRoles = MediaUsageRoleRegistry::mandatoryArticleRoles();
        $usageRows = [];
        $usageIds = [];
        $roles = [];
        $blockers = [];
        foreach ($requiredRoles as $role) {
            $rows = array_values(array_filter($this->usages->listByEndpoint('wp_post', $endpointKey, $role), static fn (mixed $usage): bool => $usage instanceof \NHK\Core\Domain\Media\MediaUsage));
            if (count($rows) !== 1) {
                $blockers[] = 'MEDIAUSAGE_INCOMPLETE';
                continue;
            }
            $usage = $rows[0];
            $expectedMediaId = trim((string) ($slots[$role]['media_id'] ?? ''));
            $usageId = trim($usage->usageId);
            $usageMediaId = trim($usage->mediaId);
            $valid = $usage->endpointType === 'wp_post'
                && $usage->endpointKey === $endpointKey
                && $usage->role === $role
                && $usageId !== ''
                && $expectedMediaId !== ''
                && $usageMediaId === $expectedMediaId
                && !$this->mediaIsPlaceholder($usageMediaId)
                && $this->mediaIsReadyAndPublic($usageMediaId);
            if (!$valid) {
                $blockers[] = 'MEDIAUSAGE_INCOMPLETE';
                $blockers[] = $role === MediaUsageRoleRegistry::FEATURED_PRIMARY ? 'ARTICLE_MEDIA_FEATURED_MISSING' : 'ARTICLE_MEDIA_INLINE_MISSING';
                continue;
            }
            $usageRows[] = $usage;
            $usageIds[] = $usageId;
            $roles[] = $role;
        }
        foreach ($diagnostics as $diagnostic) {
            $code = is_array($diagnostic) ? trim((string) ($diagnostic['code'] ?? '')) : '';
            if (in_array($code, ['MEDIAUSAGE_INCOMPLETE', 'ARTICLE_MEDIA_FEATURED_MISSING', 'ARTICLE_MEDIA_INLINE_MISSING'], true)) $blockers[] = $code;
        }
        $blockers = array_values(array_unique($blockers));
        return [
            'media_usage' => [
                'state' => $blockers === [] && count($usageRows) === count($requiredRoles) ? 'VERIFIED' : 'REVIEW_REQUIRED',
                'endpoint_type' => 'wp_post',
                'endpoint_key' => $endpointKey,
                'roles' => $roles,
                'usage_ids' => $usageIds,
                'source' => 'ARTICLE_MEDIA_RECONCILIATION',
                'blockers' => $blockers,
            ],
        ];
    }

    private function mediaIsPlaceholder(string $mediaId): bool
    {
        return $this->media->findByCanonicalId($mediaId)?->isSystemPlaceholder() ?? true;
    }

    /** @param list<string> $used */
    private function findReusable(MediaSeoBlueprint $blueprint, array $used, bool $requireSubjectScope = false, bool $allowReuseOfUsed = false): ?Media
    {
        $best = null; $bestScore = -1;
        foreach ($this->media->list() as $media) {
            // Prefer an unused candidate, but allow one canonical subject image
            // to satisfy both mandatory editorial roles when it is the only
            // eligible image. Never broaden this fallback beyond the subject
            // scope carried by the blueprint.
            if (!$allowReuseOfUsed && in_array($media->canonicalId, $used, true)) continue;
            $reusePenalty = in_array($media->canonicalId, $used, true) ? -1 : 0;
            $candidate = $this->usableMedia($media->canonicalId, $blueprint, $requireSubjectScope);
            if ($candidate === null) continue;
            $score = 1 + $reusePenalty;
            if (($blueprint->preferredView ?? '') !== '' && ($candidate->provenance['detail_type'] ?? '') === $blueprint->preferredView) $score += 3;
            foreach ($this->assets->listByMediaId($candidate->canonicalId) as $asset) if (($asset->width ?? 0) >= $blueprint->minimumWidth) $score += 2;
            if ($score > $bestScore || ($score === $bestScore && ($best === null || $candidate->stableKey < $best->stableKey))) { $best = $candidate; $bestScore = $score; }
        }
        return $best;
    }

    private function matchesSubjectScope(Media $media, MediaSeoBlueprint $blueprint, bool $requirePersistedScope = false): bool
    {
        $expected = [];
        foreach (['subject_ids', 'canonical_subject_ids'] as $key) {
            foreach ((array) ($blueprint->subjectContext[$key] ?? []) as $id) {
                $id = trim((string) $id);
                if ($id !== '') $expected[$id] = true;
            }
        }
        if ($expected === []) return !$requirePersistedScope;

        $provenance = $media->provenance;
        $metadata = is_array($provenance['metadata'] ?? null) ? $provenance['metadata'] : [];
        $actual = [];
        foreach (['subject_id', 'subject_uuid', 'canonical_subject_id', 'canonical_subject_uuid'] as $key) {
            foreach ([$provenance[$key] ?? null, $metadata[$key] ?? null] as $value) {
                $value = trim((string) $value);
                if ($value !== '') $actual[$value] = true;
            }
        }
        foreach ([(array) ($provenance['subject_ids'] ?? []), (array) ($metadata['subject_ids'] ?? [])] as $ids) {
            foreach ($ids as $id) {
                $id = trim((string) $id);
                if ($id !== '') $actual[$id] = true;
            }
        }
        foreach ($this->usages->listByMediaId($media->canonicalId) as $usage) {
            if (in_array($usage->endpointType, ['variant', 'authority_variant'], true)) $actual[trim($usage->endpointKey)] = true;
        }
        return array_intersect_key($expected, $actual) !== [];
    }

    private function placeholder(string $slot): Media
    {
        $key = 'system:placeholder:' . $slot;
        $name = $slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? 'System placeholder — featured image' : 'System placeholder — inline image';
        return $this->mediaService->create($key, $name, 'ready', ['system_role' => 'placeholder', 'slot' => $slot]);
    }

    /** @param array<string,array<string,mixed>> $slots @return array<string,mixed> */
    private function guidance(array $slots, array $context): array
    {
        $featuredMissing = ($slots[MediaUsageRoleRegistry::FEATURED_PRIMARY]['placeholder'] ?? true) === true;
        $inlineMissing = ($slots[MediaUsageRoleRegistry::INLINE_PRIMARY]['placeholder'] ?? true) === true;
        $blueprint = is_array($slots[MediaUsageRoleRegistry::FEATURED_PRIMARY]['blueprint'] ?? null) ? $slots[MediaUsageRoleRegistry::FEATURED_PRIMARY]['blueprint'] : [];
        $fallback = is_array($context['video_thumbnail_fallback'] ?? null)
            && ($context['video_thumbnail_fallback']['eligible'] ?? false) === true
            ? $context['video_thumbnail_fallback']
            : null;
        if (!$featuredMissing && !$inlineMissing && $fallback !== null) $fallback = null;
        $expectedSubject = trim((string) ($context['subject'] ?? ''));
        if ($expectedSubject === '' && is_array($context['subject_context'] ?? null)) $expectedSubject = trim((string) (($context['subject_context']['subject'] ?? '')));
        $message = $featuredMissing
            ? ($fallback !== null
                ? 'Bài đã đủ nội dung nhưng chưa có ảnh đại diện riêng. Có thể dùng ảnh thumbnail chất lượng cao của video làm ảnh tạm, hoặc bạn có thể tải ảnh đẹp hơn.'
                : 'Bài đã đủ nội dung nhưng còn thiếu ảnh đại diện. Bạn có muốn tải ảnh đại diện cho bài này không?')
            : ($inlineMissing ? 'Bài còn thiếu ảnh minh họa trong nội dung. Bạn có muốn tải ảnh cho bài này không?' : 'Hình ảnh của bài đã sẵn sàng.');
        return [
            'user_message' => $message,
            'featured_image_missing' => $featuredMissing,
            'inline_image_missing' => $inlineMissing,
            'expected_subject' => $expectedSubject !== '' ? $expectedSubject : null,
            'preferred_view' => $blueprint['preferred_view'] ?? null,
            'preferred_aspect' => $blueprint['preferred_aspect'] ?? null,
            'video_thumbnail_fallback' => $fallback,
            'user_upload_preferred' => $featuredMissing,
            'user_upload_required' => $featuredMissing && $fallback === null,
        ];
    }

    private function reconcileUsage(string $endpointKey, string $slot, string $mediaId, \NHK\Core\Domain\Media\MediaSeoBlueprint $blueprint, string $placementKey = ''): \NHK\Core\Domain\Media\MediaUsage
    {
        $conflictRetries = 0;
        while (true) {
            $existing = $this->usages->listByEndpoint('wp_post', $endpointKey, $slot);
            usort($existing, static fn (\NHK\Core\Domain\Media\MediaUsage $left, \NHK\Core\Domain\Media\MediaUsage $right): int => $left->usageId <=> $right->usageId);
            foreach ($existing as $usage) {
                if ($usage->activeSlot === 'retired') continue;
                if ($usage->mediaId !== $mediaId) continue;
                $candidate = new \NHK\Core\Domain\Media\MediaUsage($usage->usageId, $mediaId, 'wp_post', $endpointKey, $slot, 0, $blueprint->plannedAltIntent, '', $blueprint->keywordGroups, $blueprint->plannedTitle, $usage->revision, $usage->placementKey !== '' ? $usage->placementKey : $placementKey);
                if ($usage->sortOrder === $candidate->sortOrder && $usage->altText === $candidate->altText && $usage->caption === $candidate->caption && $usage->keywordGroups === $candidate->keywordGroups && $usage->title === $candidate->title && $usage->placementKey === $candidate->placementKey) return $usage;
                if (!$this->usages instanceof MediaUsageUpdater) throw new \RuntimeException('ARTICLE_MEDIA_USAGE_UPDATE_UNAVAILABLE');
                try {
                    return $this->usages->update($candidate);
                } catch (\NHK\Core\Domain\Media\MediaException $error) {
                    if (!$this->isUsageUpdateConflict($error) || $conflictRetries >= 1) throw $error;
                    ++$conflictRetries;
                    continue 2;
                }
            }
            if ($existing !== []) {
                $current = $existing[0];
                $candidate = new \NHK\Core\Domain\Media\MediaUsage($current->usageId, $mediaId, 'wp_post', $endpointKey, $slot, 0, $blueprint->plannedAltIntent, '', $blueprint->keywordGroups, $blueprint->plannedTitle, $current->revision, $current->placementKey !== '' ? $current->placementKey : $placementKey);
                if ($this->usages instanceof MediaUsageUpdater) {
                    try {
                        return $this->usages->update($candidate);
                    } catch (\NHK\Core\Domain\Media\MediaException $error) {
                        if (!$this->isUsageUpdateConflict($error) || $conflictRetries >= 1) throw $error;
                        ++$conflictRetries;
                        continue;
                    }
                }
                if ($this->usages instanceof MutableMediaUsageRepository) throw new \RuntimeException('ARTICLE_MEDIA_USAGE_REPLACEMENT_UNAVAILABLE');
            }
            return $this->mediaService->addUsage($mediaId, 'wp_post', $endpointKey, $slot, 0, $blueprint->plannedAltIntent, '', $blueprint->keywordGroups, $blueprint->plannedTitle, $placementKey);
        }
    }

    private function isUsageUpdateConflict(\NHK\Core\Domain\Media\MediaException $error): bool
    {
        return strtolower(trim($error->getMessage())) === 'media usage update conflict.';
    }

    /** @param list<mixed> $placements @return list<array{media_id:string,placement_key:string,sort_order:int}> */
    private function normalizeSupportingPlacements(array $placements): array
    {
        $seen = [];
        $normalized = [];
        foreach (array_values($placements) as $index => $placement) {
            $mediaId = is_array($placement) ? trim((string) ($placement['media_id'] ?? '')) : trim((string) $placement);
            if ($mediaId === '') continue;
            $explicitKey = is_array($placement) ? trim((string) ($placement['placement_key'] ?? '')) : '';
            if (isset($seen[$mediaId]) && $explicitKey === '') throw new \InvalidArgumentException('MEDIA_PLACEMENT_KEY_REQUIRED');
            $key = $explicitKey !== '' ? $explicitKey : 'supporting:' . hash('sha256', $mediaId);
            if (isset($seen[$mediaId . "\0" . $key])) throw new \InvalidArgumentException('MEDIA_PLACEMENT_KEY_DUPLICATE');
            $seen[$mediaId] = true;
            $seen[$mediaId . "\0" . $key] = true;
            $normalized[] = ['media_id' => $mediaId, 'placement_key' => $key, 'sort_order' => is_array($placement) && isset($placement['sort_order']) ? max(0, (int) $placement['sort_order']) : $index];
        }
        return $normalized;
    }
}
