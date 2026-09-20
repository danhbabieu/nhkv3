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
        private ?SemanticSuitabilityPolicy $suitabilityPolicy = null,
    ) {}

    /** @param array<string,mixed> $context @param array<string,string|array<string,mixed>> $selectedMediaBySlot @param list<string|array<string,mixed>> $supportingMediaIds */
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
        $singleRealImageException = ($context['single_real_image_exception'] ?? false) === true;
        $contentIntent = strtoupper(trim((string) ($context['content_intent']['intent'] ?? '')));
        $enforceDistinctMandatoryMedia = $contentIntent !== '' && !$singleRealImageException;
        $allowHistoricalReuse = !$captureMediaContext
            ? (($context['allow_unscoped_reuse'] ?? true) === true)
            : ($subjectScopeLocked && (($context['allow_scoped_reuse'] ?? false) === true || ($context['allow_unscoped_reuse'] ?? false) === true));
        $allowHistoricalSubjectReuse = !$captureMediaContext
            ? $allowHistoricalReuse
            : ($subjectScopeLocked && (($context['allow_scoped_reuse'] ?? false) === true || ($context['allow_unscoped_reuse'] ?? false) === true));
        $editorial = $this->wordpress?->read($postId);
        $selectedContextBySlot = [];
        foreach ($selectedMediaBySlot as $slot => $selection) {
            // The public Article contract historically accepted a compact
            // slot => Media ID map. Keep that shape lossless while allowing
            // the Capture path to carry explicit selection provenance and
            // contextual metadata in the structured form.
            if (!is_array($selection)) $selection = ['media_id' => (string) $selection];
            $selectedContextBySlot[$slot] = [
                'title' => (string) ($selection['title'] ?? ''),
                'alt_text' => (string) ($selection['alt_text'] ?? ''),
                'caption' => (string) ($selection['caption'] ?? ''),
                'sort_order' => array_key_exists('sort_order', $selection) ? max(0, (int) $selection['sort_order']) : 0,
                'selection_source' => strtoupper(trim((string) ($selection['selection_source'] ?? ''))) ?: 'USER_EXPLICIT',
                'selection_policy' => strtoupper(trim((string) ($selection['selection_policy'] ?? ''))) ?: 'PINNED',
            ];
            $selectedMediaBySlot[$slot] = (string) ($selection['media_id'] ?? '');
        }
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
        foreach (MediaUsageRoleRegistry::mandatoryArticleRoles() as $slot) {
            $blueprint = MediaSeoBlueprint::forPost($postId, $slot, $context, MediaSeoStateRegistry::PLACEHOLDER);
            $existing = $this->existingSlotMedia($endpointKey, $slot);
            $existingUsage = $this->existingSlotUsage($endpointKey, $slot);
            $candidateId = trim((string) ($selectedMediaBySlot[$slot] ?? ''));
            if ($slot === MediaUsageRoleRegistry::INLINE_PRIMARY && $enforceDistinctMandatoryMedia && $candidateId !== '' && $candidateId === ($slotMedia[MediaUsageRoleRegistry::FEATURED_PRIMARY] ?? '')) $candidateId = '';
            $candidateIsCaptureOwned = $candidateId !== '' && in_array($candidateId, $captureOwnedMediaIds, true);
            $candidateRequiresScope = $subjectScopeLocked && !($captureMediaContext && $candidateIsCaptureOwned);
            $candidate = $candidateId !== '' ? $this->usableMedia($candidateId, $blueprint, $candidateRequiresScope) : null;
            if ($candidate === null && $allowHistoricalSubjectReuse && $existing !== null && !in_array($existing->canonicalId, array_values($slotMedia), true)) $candidate = $this->usableMedia($existing->canonicalId, $blueprint, $subjectScopeLocked);
            if ($candidate === null && $allowHistoricalSubjectReuse) $candidate = $this->findReusable($blueprint, array_values($slotMedia), $subjectScopeLocked, !$enforceDistinctMandatoryMedia);
            if ($candidate === null) $candidate = $this->placeholder($slot);
            // Do not overwrite an existing semantically invalid usage with a
            // placeholder before the governed reconciliation plan sees it.
            // The persisted row remains auditable and is retired through the
            // normal MediaUsage apply boundary when no replacement exists.
            $usage = $candidate->isSystemPlaceholder() && $existingUsage instanceof \NHK\Core\Domain\Media\MediaUsage
                ? $existingUsage
                : $this->reconcileUsage($endpointKey, $slot, $candidate->canonicalId, $blueprint, 'article:' . $endpointKey . ':' . $slot, $selectedContextBySlot[$slot] ?? []);
            $state = $candidate->isSystemPlaceholder() ? ($slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? MediaSeoStateRegistry::INCOMPLETE_FEATURED : MediaSeoStateRegistry::INCOMPLETE_INLINE) : MediaSeoStateRegistry::COMPLETE;
            if ($candidate->isSystemPlaceholder()) $diagnostics[] = ['code' => $slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? 'ARTICLE_MEDIA_FEATURED_MISSING' : 'ARTICLE_MEDIA_INLINE_MISSING', 'slot' => $slot, 'media_id' => $candidate->canonicalId];
            if ($candidate->isSystemPlaceholder() && $candidateId !== '') $diagnostics[] = ['code' => 'MEDIA_CANDIDATE_INELIGIBLE', 'slot' => $slot, 'media_id' => $candidateId, 'reason' => 'PERSISTED_SUBJECT_SCOPE_MISMATCH'];
            $assessment = $candidate->isSystemPlaceholder()
                ? ['requirement' => $contentIntent === 'IMAGE_ARTICLE' ? SemanticSuitabilityPolicy::REQUIRED : SemanticSuitabilityPolicy::OPTIONAL, 'availability' => SemanticSuitabilityPolicy::MISSING, 'suitability' => SemanticSuitabilityPolicy::UNKNOWN, 'basis' => 'no_candidate', 'auto_select' => false, 'valid_for_completeness' => false, 'diagnostic' => 'MEDIA_OPTIONAL_MISSING']
                : ($this->suitabilityPolicy ??= new SemanticSuitabilityPolicy())->evaluateMedia($candidate, $this->assets->listByMediaId($candidate->canonicalId), ['subject_ids' => $subjectIds, 'current_capture_media' => $candidateIsCaptureOwned, 'article_explicit_media' => (($selectedContextBySlot[$slot]['selection_source'] ?? '') === 'USER_EXPLICIT')], (string) ($selectedContextBySlot[$slot]['selection_source'] ?? 'SYSTEM_AUTO'), $slot);
            $blueprint = MediaSeoBlueprint::forPost($postId, $slot, $context, $state);
            $this->blueprints->save($blueprint);
            $slotMedia[$slot] = $candidate->canonicalId;
            $effective = !$candidate->isSystemPlaceholder() && (($assessment['valid_for_completeness'] ?? false) === true);
            if (!$effective && !$candidate->isSystemPlaceholder()) {
                // A readable historical usage is retained for audit, but it is
                // not an effective slot and must never satisfy completeness.
                $slotMedia[$slot] = '';
            }
            $slots[$slot] = ['media_id' => $effective ? $candidate->canonicalId : '', 'persisted_media_id' => $candidate->isSystemPlaceholder() ? ($existing?->canonicalId) : $candidate->canonicalId, 'placeholder' => !$effective, 'state' => $effective ? MediaSeoStateRegistry::COMPLETE : ($slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? MediaSeoStateRegistry::INCOMPLETE_FEATURED : MediaSeoStateRegistry::INCOMPLETE_INLINE), 'suitability' => $assessment['suitability'], 'availability' => $assessment['availability'], 'valid_for_completeness' => $effective, 'placement_key' => $usage->placementKey, 'placement_anchor' => $usage->placementAnchor(), 'blueprint' => $blueprint->toArray()];
            if (!$effective && !$candidate->isSystemPlaceholder()) $diagnostics[] = ['code' => 'MEDIA_USAGE_SEMANTIC_MISMATCH', 'slot' => $slot, 'media_id' => $candidate->canonicalId];
        }
        $supportingPlacements = $this->normalizeSupportingPlacements($supportingMediaIds);
        foreach ($supportingPlacements as $placement) {
            $index = $placement['sort_order'];
            $supportingId = $placement['media_id'];
            $supportingRequiresScope = $subjectScopeLocked && !($captureMediaContext && in_array($supportingId, $captureOwnedMediaIds, true));
            $candidate = $this->usableMedia($supportingId, MediaSeoBlueprint::forPost($postId, MediaUsageRoleRegistry::INLINE_PRIMARY, $context), $supportingRequiresScope);
            if ($candidate !== null) $this->mediaService->addUsage(
                $candidate->canonicalId,
                'wp_post',
                $endpointKey,
                MediaUsageRoleRegistry::INLINE_SUPPORTING,
                $index,
                $placement['alt_text'],
                $placement['caption'],
                [],
                $placement['title'],
                $placement['placement_key'],
            );
        }
        $desiredUsages = [];
        foreach ($slotMedia as $role => $mediaId) {
            if (trim((string) $mediaId) === '') continue;
            $desiredUsages[] = ['role' => $role, 'media_id' => $mediaId, 'sort_order' => 0, 'placement_key' => (string) ($slots[$role]['placement_key'] ?? ''), 'alt_text' => (string) ($slots[$role]['blueprint']['planned_alt_intent'] ?? ''), 'title' => (string) ($slots[$role]['blueprint']['planned_title'] ?? ''), 'keyword_groups' => (array) ($slots[$role]['blueprint']['keyword_groups'] ?? []), 'selection_source' => in_array($mediaId, $captureOwnedMediaIds, true) ? 'USER_EXPLICIT' : 'SYSTEM_AUTO', 'selection_policy' => in_array($mediaId, $captureOwnedMediaIds, true) ? 'PINNED' : 'AUTO', 'current_capture_media' => in_array($mediaId, $captureOwnedMediaIds, true)];
        }
        foreach ($supportingPlacements as $placement) $desiredUsages[] = [
            'role' => MediaUsageRoleRegistry::INLINE_SUPPORTING,
            'media_id' => $placement['media_id'],
            'sort_order' => $placement['sort_order'],
            'placement_key' => $placement['placement_key'],
            'alt_text' => $placement['alt_text'],
            'caption' => $placement['caption'],
            'title' => $placement['title'],
        ];
        $usagePlan = (new MediaUsageReconciler())->plan(
            'wp_post',
            $endpointKey,
            $this->usages->listByEndpoint('wp_post', $endpointKey),
            $desiredUsages,
            function (string $mediaId, string $role, array $spec) use ($subjectIds): array {
                $media = $this->media->findByCanonicalId($mediaId);
                if (!$media instanceof Media) return ['valid_for_completeness' => false, 'diagnostic' => 'MEDIA_USAGE_MEDIA_NOT_FOUND'];
                return ($this->suitabilityPolicy ??= new SemanticSuitabilityPolicy())->evaluateMedia(
                    $media,
                    $this->assets->listByMediaId($mediaId),
                    ['subject_ids' => $subjectIds, 'current_capture_media' => ($spec['current_capture_media'] ?? false) === true],
                    (string) ($spec['selection_source'] ?? 'SYSTEM_AUTO'),
                    $role,
                );
            },
        );
        $diagnostics[] = ['phase' => 'plan', 'code' => 'MEDIA_USAGE_RECONCILIATION', 'status' => $usagePlan['status'], 'actions' => $usagePlan['actions']];
        $state = array_filter($slots, static fn (array $slot): bool => $slot['placeholder'] || ($slot['valid_for_completeness'] ?? false) !== true) !== [] ? MediaSeoStateRegistry::PLACEHOLDER : (in_array('MEDIA_LOW_RESOLUTION', array_column($diagnostics, 'code'), true) ? MediaSeoStateRegistry::LOW_RESOLUTION : MediaSeoStateRegistry::COMPLETE);
        $guidance = $this->guidance($slots, $context);
        $result = new ArticleMediaResult($postId, $endpointKey, $state, $slotMedia, $slots, $diagnostics, is_array($editorial) ? (string) ($editorial['state_token'] ?? '') : '', $guidance);
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
            // The synchronizer may have changed native editorial placements or
            // the governed usage rows. Rebuild the public result from the
            // same suitability policy and persisted usage read-back used by
            // preflight; never expose the pre-reconciliation plan as final
            // media truth.
            $canonical = $subjectIds === [] ? $result : $this->diagnoseForPost($postId, $context);
            $planSnapshot = ['phase' => 'plan', 'code' => 'MEDIA_USAGE_RECONCILIATION', 'status' => $usagePlan['status'], 'actions' => $usagePlan['actions']];
            $finalDiagnostics = array_merge([$planSnapshot], $canonical->diagnostics);
            $finalSlotMedia = $canonical->slotMedia;
            foreach ($canonical->slots as $slot => $slotState) {
                if (($slotState['valid_for_completeness'] ?? false) !== true && ($result->slots[$slot]['placeholder'] ?? false) === true) {
                    $finalSlotMedia[$slot] = (string) ($result->slotMedia[$slot] ?? '');
                }
            }
            $result = new ArticleMediaResult($postId, $endpointKey, $canonical->state, $finalSlotMedia, $canonical->slots, $finalDiagnostics, (string) ($readback['state_token'] ?? $canonical->editorialStateToken), $canonical->guidance);
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
        $context = $this->normalizeSubjectContext($context);
        $subjectIds = array_values(array_filter(array_map('strval', (array) ($context['subject_ids'] ?? [])), static fn (string $id): bool => trim($id) !== ''));
        $slots = []; $slotMedia = []; $diagnostics = [];
        foreach (MediaUsageRoleRegistry::mandatoryArticleRoles() as $slot) {
            $existing = $this->existingSlotMedia($endpointKey, $slot);
            $captureOwned = $existing instanceof Media && in_array($existing->canonicalId, array_values(array_filter(array_map('strval', (array) ($context['capture_owned_media_ids'] ?? [])))), true);
            $assessment = $existing instanceof Media ? ($this->suitabilityPolicy ??= new SemanticSuitabilityPolicy())->evaluateMedia($existing, $this->assets->listByMediaId($existing->canonicalId), ['subject_ids' => $subjectIds, 'current_capture_media' => $captureOwned && (($this->existingSlotUsage($endpointKey, $slot)?->selectionSource ?? 'SYSTEM_AUTO') === 'USER_EXPLICIT')], $captureOwned && (($this->existingSlotUsage($endpointKey, $slot)?->selectionSource ?? 'SYSTEM_AUTO') === 'USER_EXPLICIT') ? 'USER_EXPLICIT' : 'SYSTEM_AUTO', $slot) : ['valid_for_completeness' => false, 'suitability' => SemanticSuitabilityPolicy::UNKNOWN, 'availability' => SemanticSuitabilityPolicy::MISSING, 'diagnostic' => 'MEDIA_USAGE_INCOMPLETE'];
            $valid = ($assessment['valid_for_completeness'] ?? false) === true && $existing instanceof Media && !$existing->isSystemPlaceholder();
            $placeholder = !$valid;
            $id = $valid ? $existing->canonicalId : '';
            $slotMedia[$slot] = $id;
            $slots[$slot] = ['media_id' => $id, 'persisted_media_id' => $existing?->canonicalId, 'placeholder' => $placeholder, 'suitability' => $assessment['suitability'], 'availability' => $assessment['availability'], 'valid_for_completeness' => $valid, 'state' => $placeholder ? ($slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? MediaSeoStateRegistry::INCOMPLETE_FEATURED : MediaSeoStateRegistry::INCOMPLETE_INLINE) : MediaSeoStateRegistry::COMPLETE, 'blueprint' => ($this->blueprints->findByPostAndSlot($postId, $slot) ?? MediaSeoBlueprint::forPost($postId, $slot, $context))->toArray()];
            if ($placeholder) $diagnostics[] = ['code' => $slot === MediaUsageRoleRegistry::FEATURED_PRIMARY ? 'ARTICLE_MEDIA_FEATURED_MISSING' : 'ARTICLE_MEDIA_INLINE_MISSING', 'slot' => $slot];
            if (!$valid && $existing instanceof Media && $assessment['diagnostic'] !== null) $diagnostics[] = ['code' => $assessment['diagnostic'], 'slot' => $slot, 'media_id' => $existing->canonicalId];
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

    private function existingSlotUsage(string $endpointKey, string $slot): ?\NHK\Core\Domain\Media\MediaUsage
    {
        foreach ($this->usages->listByEndpoint('wp_post', $endpointKey, $slot) as $usage) {
            if ($usage->activeSlot !== 'retired') return $usage;
        }
        return null;
    }

    private function usableMedia(string $id, MediaSeoBlueprint $blueprint, bool $requireSubjectScope = false): ?Media
    {
        $media = $this->media->findByCanonicalId($id);
        if ($media === null || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return null;
        if ($requireSubjectScope && !$this->matchesSubjectScope($media, $blueprint, true)) return null;
        if ($requireSubjectScope) {
            $assessment = ($this->suitabilityPolicy ??= new SemanticSuitabilityPolicy())->evaluateMedia($media, $this->assets->listByMediaId($media->canonicalId), ['subject_ids' => $this->subjectIdsFromBlueprint($blueprint)]);
            if (($assessment['valid_for_completeness'] ?? false) !== true) return null;
        }
        return $this->assets->listByMediaId($media->canonicalId) === [] ? null : $media;
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

    /** @return list<string> */
    private function subjectIdsFromBlueprint(MediaSeoBlueprint $blueprint): array
    {
        $ids = [];
        foreach (['subject_ids', 'canonical_subject_ids'] as $key) foreach ((array) ($blueprint->subjectContext[$key] ?? []) as $id) {
            $id = trim((string) $id);
            if ($id !== '') $ids[$id] = true;
        }
        return array_keys($ids);
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
            'user_upload_required' => strtoupper(trim((string) ($context['content_intent']['intent'] ?? 'TEXT_ARTICLE'))) === 'IMAGE_ARTICLE' && $featuredMissing && $fallback === null,
            'upload_required' => strtoupper(trim((string) ($context['content_intent']['intent'] ?? 'TEXT_ARTICLE'))) === 'IMAGE_ARTICLE' && $featuredMissing && $fallback === null,
        ];
    }

    private function reconcileUsage(string $endpointKey, string $slot, string $mediaId, \NHK\Core\Domain\Media\MediaSeoBlueprint $blueprint, string $placementKey = '', array $metadata = []): \NHK\Core\Domain\Media\MediaUsage
    {
        $altText = array_key_exists('alt_text', $metadata) ? (string) $metadata['alt_text'] : $blueprint->plannedAltIntent;
        $title = array_key_exists('title', $metadata) ? (string) $metadata['title'] : $blueprint->plannedTitle;
        $caption = array_key_exists('caption', $metadata) ? (string) $metadata['caption'] : '';
        $sortOrder = array_key_exists('sort_order', $metadata) ? max(0, (int) $metadata['sort_order']) : 0;
        $selectionSource = (string) ($metadata['selection_source'] ?? 'SYSTEM_AUTO');
        $selectionPolicy = (string) ($metadata['selection_policy'] ?? 'AUTO');
        $conflictRetries = 0;
        while (true) {
            $existing = $this->usages->listByEndpoint('wp_post', $endpointKey, $slot);
            usort($existing, static fn (\NHK\Core\Domain\Media\MediaUsage $left, \NHK\Core\Domain\Media\MediaUsage $right): int => $left->usageId <=> $right->usageId);
            foreach ($existing as $usage) {
                if ($usage->activeSlot === 'retired') continue;
                if ($usage->mediaId !== $mediaId) continue;
                $candidate = new \NHK\Core\Domain\Media\MediaUsage($usage->usageId, $mediaId, 'wp_post', $endpointKey, $slot, $sortOrder, $altText, $caption, $blueprint->keywordGroups, $title, $usage->revision, $usage->placementKey !== '' ? $usage->placementKey : $placementKey, $selectionSource, $selectionPolicy);
                if ($usage->sortOrder === $candidate->sortOrder && $usage->altText === $candidate->altText && $usage->caption === $candidate->caption && $usage->keywordGroups === $candidate->keywordGroups && $usage->title === $candidate->title && $usage->placementKey === $candidate->placementKey && $usage->selectionSource === $candidate->selectionSource && $usage->selectionPolicy === $candidate->selectionPolicy) return $usage;
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
                $candidate = new \NHK\Core\Domain\Media\MediaUsage($current->usageId, $mediaId, 'wp_post', $endpointKey, $slot, $sortOrder, $altText, $caption, $blueprint->keywordGroups, $title, $current->revision, $current->placementKey !== '' ? $current->placementKey : $placementKey, $selectionSource, $selectionPolicy);
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
            return $this->mediaService->addUsage($mediaId, 'wp_post', $endpointKey, $slot, $sortOrder, $altText, $caption, $blueprint->keywordGroups, $title, $placementKey, $selectionSource, $selectionPolicy);
        }
    }

    private function isUsageUpdateConflict(\NHK\Core\Domain\Media\MediaException $error): bool
    {
        return strtolower(trim($error->getMessage())) === 'media usage update conflict.';
    }

    /** @param list<mixed> $placements @return list<array{media_id:string,placement_key:string,sort_order:int,alt_text:string,caption:string,title:string}> */
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
            $normalized[array_key_last($normalized)] += [
                'alt_text' => is_array($placement) ? (string) ($placement['alt_text'] ?? '') : '',
                'caption' => is_array($placement) ? (string) ($placement['caption'] ?? '') : '',
                'title' => is_array($placement) ? (string) ($placement['title'] ?? '') : '',
            ];
        }
        return $normalized;
    }
}
