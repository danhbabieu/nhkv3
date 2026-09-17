<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaBindingOperationRepository, MediaBindingPort, MediaRepository, MediaUsageRepository, MediaUsageUpdater};
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Media\{Media, MediaAsset, MediaBindingOperation, MediaException, MediaUsage, MediaUsageRoleRegistry, RepresentativeEligibilityRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * The sole application owner for Media -> MediaUsage consumer binding.
 * Callers provide an exact target or an explicitly supported Media locator;
 * this service never uses NLP, Graph traversal or filename similarity as proof.
 */
final class MediaBindingService implements MediaBindingPort
{
    public function __construct(
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private MediaUsageRepository $usages,
        private AuthorityRepository $authority,
        private EntityTypeRegistry $types,
        private ?MediaBindingOperationRepository $operations = null,
        private RepresentativeEligibilityRegistry $eligibility = new RepresentativeEligibilityRegistry(),
    ) {}

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function bind(array $request): array
    {
        $normalized = $this->normalizeRequest($request);
        $key = (string) $normalized['idempotency_key'];
        $fingerprint = $this->fingerprint($normalized);
        // Stable-key target locators are resolved once before a durable receipt
        // is created so the receipt always contains the canonical target UUID.
        if ((string) ($normalized['target']['id'] ?? '') === '') {
            $resolvedTarget = $this->resolveTarget((array) $normalized['target']);
            $normalized['target']['id'] = $resolvedTarget->canonicalId;
        }
        $operation = $this->operations?->findByIdempotencyKey($key);
        if ($operation !== null) {
            if (!hash_equals($operation->requestFingerprint, $fingerprint)) throw new MediaException('IDEMPOTENCY_CONFLICT');
            if ($operation->status === 'COMPLETE') return $operation->toArray();
        } else {
            $target = (array) $normalized['target'];
            $operation = new MediaBindingOperation(UuidCodec::newV7(), $key, $fingerprint, null, (string) $target['type'], (string) $target['id'], (string) $normalized['role'], (string) $normalized['selection_source'], (string) $normalized['selection_policy'], metadata: ['seo' => $normalized['seo']]);
            if ($this->operations !== null) {
                $created = $this->operations->create($operation);
                if ($created->idempotencyKey !== $key || !hash_equals($created->requestFingerprint, $fingerprint)) {
                    throw new MediaException('IDEMPOTENCY_CONFLICT');
                }
                $operation = $created;
                if ($operation->status === 'COMPLETE') return $operation->toArray();
            }
        }

        try {
            $operation = $this->advance($operation, MediaBindingOperation::VALIDATE, 'IN_PROGRESS');
            $media = $this->resolveMedia((array) $normalized['media']);
            $operation = $this->advance($operation, MediaBindingOperation::RESOLVE_MEDIA, 'IN_PROGRESS', $media->canonicalId);
            $target = $this->resolveTarget((array) $normalized['target']);
            $operation = $this->advance($operation, MediaBindingOperation::RESOLVE_TARGET, 'IN_PROGRESS', $media->canonicalId, metadata: ['seo' => $normalized['seo'], 'target_stable_key' => $target->stableKey]);
            $operation = $this->advance($operation, MediaBindingOperation::PLAN, 'IN_PROGRESS', $media->canonicalId);
            $usage = $this->applyUsage($media, $target->entityType, $target->canonicalId, $normalized, $operation);
            $operation = $this->advance($operation, MediaBindingOperation::APPLY_USAGE, 'IN_PROGRESS', $media->canonicalId, $usage['usage']->usageId, $usage['previous_usage_id']);
            $operation = $this->advance($operation, MediaBindingOperation::RECONCILE_REPRESENTATIVE, 'IN_PROGRESS', $media->canonicalId, $usage['usage']->usageId, $usage['previous_usage_id']);
            if (function_exists('do_action')) do_action('nhk_v3_media_binding_seo_invalidate', $target->entityType, $target->canonicalId, $usage['usage']->usageId);
            $operation = $this->advance($operation, MediaBindingOperation::SEO_INVALIDATE, 'IN_PROGRESS', $media->canonicalId, $usage['usage']->usageId, $usage['previous_usage_id']);
            if (function_exists('do_action')) do_action('nhk_v3_media_binding_projection_invalidate', $target->entityType, $target->canonicalId, $usage['usage']->usageId);
            $operation = $this->advance($operation, MediaBindingOperation::PROJECTION_INVALIDATE, 'IN_PROGRESS', $media->canonicalId, $usage['usage']->usageId, $usage['previous_usage_id']);
            $readback = $this->readback($media->canonicalId, $target->entityType, $target->canonicalId, $usage['usage']->usageId);
            $operation = $this->advance($operation, MediaBindingOperation::FINAL_READBACK, 'IN_PROGRESS', $media->canonicalId, $usage['usage']->usageId, $usage['previous_usage_id'], ['readback' => $readback]);
            $operation = $this->advance($operation, MediaBindingOperation::COMPLETE, 'COMPLETE', $media->canonicalId, $usage['usage']->usageId, $usage['previous_usage_id'], ['readback' => $readback, 'reconciliation' => $usage['reconciliation']]);
            return $operation->toArray() + ['usage' => $this->usageArray($usage['usage']), 'readback' => $readback, 'reconciliation' => $usage['reconciliation']];
        } catch (\Throwable $error) {
            $code = preg_replace('/[^A-Z0-9_:-]+/', '_', strtoupper(trim($error->getMessage()))) ?: 'MEDIA_BINDING_FAILED';
            if ($this->operations !== null) {
                try { $this->advance($operation, $operation->stage, 'FAILED_RETRYABLE', $operation->mediaId, $operation->resultingUsageId, $operation->previousUsageId, ['error_code' => $code]); } catch (\Throwable) { }
            }
            throw $error;
        }
    }

    /** @param list<array<string,mixed>> $bindings @param list<array<string,mixed>> $assets @return array<string,mixed> */
    public function bindMany(array $bindings, string $idempotencyKey, array $assets = []): array
    {
        if (!array_is_list($bindings) || $bindings === []) throw new MediaException('MEDIA_BINDINGS_INVALID');
        $results = [];
        foreach ($bindings as $index => $binding) {
            if (!is_array($binding)) throw new MediaException('MEDIA_BINDING_INVALID');
            $mediaRef = is_array($binding['media_ref'] ?? null) ? $binding['media_ref'] : [];
            $mediaId = trim((string) ($mediaRef['media_id'] ?? ''));
            if ($mediaId === '' && isset($mediaRef['item_index'])) {
                $asset = $assets[(int) $mediaRef['item_index']] ?? null;
                $mediaId = is_array($asset) ? trim((string) ($asset['media_id'] ?? '')) : '';
            }
            if (!UuidCodec::isValid($mediaId)) throw new MediaException('MEDIA_BINDING_MEDIA_REFERENCE_INVALID');
            $item = $binding;
            $item['media'] = ['id' => $mediaId];
            $item['idempotency_key'] = trim((string) ($binding['idempotency_key'] ?? '')) ?: $idempotencyKey . ':' . $index;
            $results[] = $this->bind($item);
        }
        return ['status' => 'COMPLETE', 'bindings' => $results, 'media_ids' => array_values(array_unique(array_map(static fn (array $item): string => (string) ($item['media_id'] ?? ''), $results)))];
    }

    public function get(string $operationId = '', string $idempotencyKey = ''): ?array
    {
        $operation = $operationId !== '' ? $this->operations?->findByOperationId($operationId) : $this->operations?->findByIdempotencyKey($idempotencyKey);
        return $operation?->toArray();
    }

    /** Resolve a supported exact Media locator without creating or mutating anything. */
    public function resolveMediaReference(array $reference): Media
    {
        return $this->resolveMedia($reference);
    }

    /** Resolve an exact Authority target for governed staging/eligibility preflight. */
    public function resolveTargetReference(array $reference): \NHK\Core\Domain\Authority\AuthorityEntity
    {
        return $this->resolveTarget($reference);
    }

    /**
     * Bind one Media to the highest-scoring eligible target from a bounded,
     * caller-supplied neighborhood. This method never traverses Graph and
     * never treats reachability or text similarity as eligibility proof.
     * @param list<array<string,mixed>> $candidates
     * @param array<string,string> $seo
     * @return array<string,mixed>
     */
    public function autoDiscoverAndBind(string $mediaId, array $candidates, string $idempotencyKey, array $seo = []): array
    {
        if (!array_is_list($candidates) || count($candidates) > 50) throw new MediaException('MEDIA_BINDING_DISCOVERY_BOUNDS_INVALID');
        $eligible = array_values(array_filter($candidates, function (mixed $candidate): bool {
            if (!is_array($candidate)) return false;
            return $this->eligibility->isEligible((string) ($candidate['target']['type'] ?? $candidate['target_type'] ?? ''), $candidate)
                && trim((string) ($candidate['target']['id'] ?? $candidate['target_id'] ?? '')) !== '';
        }));
        foreach ($eligible as &$candidate) {
            $candidate['score'] = ((int) ($candidate['semantic_specificity'] ?? 0) * 100)
                + ((int) ($candidate['visual_subject_coverage'] ?? 0) * 20)
                + ((int) ($candidate['technical_usefulness'] ?? 0) * 10)
                + ((int) ($candidate['clarity_resolution'] ?? 0) * 5)
                + ((int) ($candidate['provenance_confidence'] ?? 0) * 3)
                - ((int) ($candidate['obstruction'] ?? 0) * 2);
        }
        unset($candidate);
        usort($eligible, static fn (array $left, array $right): int => ((int) $right['score'] <=> (int) $left['score']) ?: strcmp((string) ($left['target']['id'] ?? $left['target_id'] ?? ''), (string) ($right['target']['id'] ?? $right['target_id'] ?? '')));
        if ($eligible === []) return ['status' => 'REVIEW_REQUIRED', 'reason' => 'NO_ELIGIBLE_REPRESENTATIVE_TARGET', 'candidates' => []];
        if (isset($eligible[1]) && (int) $eligible[1]['score'] === (int) $eligible[0]['score']) return ['status' => 'REVIEW_REQUIRED', 'reason' => 'REPRESENTATIVE_TARGET_AMBIGUOUS', 'candidates' => $eligible];
        $target = is_array($eligible[0]['target'] ?? null) ? $eligible[0]['target'] : ['type' => (string) ($eligible[0]['target_type'] ?? ''), 'id' => (string) ($eligible[0]['target_id'] ?? '')];
        $result = $this->bind(['idempotency_key' => $idempotencyKey, 'media' => ['id' => $mediaId], 'target' => $target, 'role' => MediaUsageRoleRegistry::REPRESENTATIVE, 'selection_source' => 'SYSTEM_AUTO', 'selection_policy' => 'AUTO', 'seo' => $seo]);
        return ['status' => 'COMPLETE', 'selected_candidate' => $eligible[0], 'binding' => $result];
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function normalizeRequest(array $request): array
    {
        $media = is_array($request['media'] ?? null) ? $request['media'] : [];
        $target = is_array($request['target'] ?? null) ? $request['target'] : [];
        $mediaId = trim((string) ($media['id'] ?? ''));
        $targetId = trim((string) ($target['id'] ?? ''));
        $source = strtoupper(trim((string) ($request['selection_source'] ?? 'USER_EXPLICIT')));
        $policy = strtoupper(trim((string) ($request['selection_policy'] ?? ($source === 'SYSTEM_AUTO' ? 'AUTO' : 'PINNED'))));
        $role = trim((string) ($request['role'] ?? MediaUsageRoleRegistry::REPRESENTATIVE));
        if (trim((string) ($request['idempotency_key'] ?? '')) === '' || !is_array($media) || !is_array($target) || $role !== MediaUsageRoleRegistry::REPRESENTATIVE) throw new MediaException('MEDIA_BINDING_REQUEST_INVALID');
        if ($targetId === '' && trim((string) ($target['stable_key'] ?? '')) === '') throw new MediaException('MEDIA_BINDING_TARGET_REFERENCE_REQUIRED');
        if ($mediaId === '' && trim((string) ($media['stable_key'] ?? '')) === '' && (int) ($media['attachment_id'] ?? 0) < 1 && trim((string) ($media['url'] ?? '')) === '') throw new MediaException('MEDIA_BINDING_MEDIA_REFERENCE_REQUIRED');
        if (!in_array($source, ['USER_EXPLICIT', 'SYSTEM_AUTO'], true) || !in_array($policy, ['PINNED', 'AUTO'], true) || ($source === 'USER_EXPLICIT' && $policy !== 'PINNED') || ($source === 'SYSTEM_AUTO' && $policy !== 'AUTO')) throw new MediaException('MEDIA_BINDING_SELECTION_INVALID');
        if ($targetId !== '' && !UuidCodec::isValid($targetId)) throw new MediaException('MEDIA_BINDING_TARGET_ID_INVALID');
        $seo = is_array($request['seo'] ?? null) ? $request['seo'] : [];
        foreach (['alt_text' => 1000, 'caption' => 2000, 'title' => 255] as $key => $limit) if (strlen((string) ($seo[$key] ?? '')) > $limit) throw new MediaException('MEDIA_BINDING_SEO_INVALID');
        return ['idempotency_key' => trim((string) $request['idempotency_key']), 'media' => $media, 'target' => ['type' => strtolower(trim((string) ($target['type'] ?? ''))), 'id' => $targetId, 'stable_key' => trim((string) ($target['stable_key'] ?? ''))], 'role' => $role, 'selection_source' => $source, 'selection_policy' => $policy, 'seo' => ['alt_text' => (string) ($seo['alt_text'] ?? ''), 'caption' => (string) ($seo['caption'] ?? ''), 'title' => (string) ($seo['title'] ?? '')]];
    }

    /** @param array<string,mixed> $request */
    private function fingerprint(array $request): string
    {
        unset($request['idempotency_key']);
        return hash('sha256', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $reference */
    private function resolveMedia(array $reference): Media
    {
        $media = null;
        $id = trim((string) ($reference['id'] ?? ''));
        if ($id !== '') {
            if (!UuidCodec::isValid($id)) throw new MediaException('MEDIA_BINDING_MEDIA_ID_INVALID');
            $media = $this->media->findByCanonicalId($id);
        } elseif (trim((string) ($reference['stable_key'] ?? '')) !== '') {
            $media = $this->media->findByStableKey(trim((string) $reference['stable_key']));
        } elseif ((int) ($reference['attachment_id'] ?? 0) > 0) {
            foreach ($this->media->list(true) as $candidate) foreach ($this->assets->listByMediaId($candidate->canonicalId) as $asset) if ((int) ($asset->metadata['wordpress_attachment_id'] ?? 0) === (int) $reference['attachment_id']) { $media = $candidate; break 2; }
        } elseif (trim((string) ($reference['url'] ?? '')) !== '') {
            $url = trim((string) $reference['url']);
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
            foreach ($this->media->list(true) as $candidate) foreach ($this->assets->listByMediaId($candidate->canonicalId) as $asset) {
                $publicPath = (string) ($asset->metadata['public_url_path'] ?? '');
                if ($publicPath !== '' && $publicPath === $path) { $media = $candidate; break 2; }
            }
        }
        if (!$media instanceof Media || !$media->active || $media->isSystemPlaceholder()) throw new MediaException('MEDIA_BINDING_MEDIA_NOT_FOUND');
        return $media;
    }

    /** @param array<string,mixed> $reference */
    private function resolveTarget(array $reference): \NHK\Core\Domain\Authority\AuthorityEntity
    {
        $type = strtolower(trim((string) ($reference['type'] ?? '')));
        if (!$this->types->has($type)) throw new MediaException('MEDIA_BINDING_TARGET_TYPE_INVALID');
        $target = trim((string) ($reference['id'] ?? '')) !== '' ? $this->authority->findByCanonicalId((string) $reference['id']) : $this->authority->findByStableKey($type, trim((string) ($reference['stable_key'] ?? '')));
        if (!$target instanceof \NHK\Core\Domain\Authority\AuthorityEntity || $target->entityType !== $type || !$target->active()) throw new MediaException('MEDIA_BINDING_TARGET_NOT_FOUND');
        return $target;
    }

    /** @param array<string,mixed> $normalized @return array{usage:MediaUsage,previous_usage_id:?string,reconciliation:string} */
    private function applyUsage(Media $media, string $targetType, string $targetId, array $normalized, MediaBindingOperation $operation): array
    {
        $current = array_values(array_filter($this->usages->listByEndpoint($targetType, $targetId, MediaUsageRoleRegistry::REPRESENTATIVE), static fn (mixed $item): bool => $item instanceof MediaUsage));
        if (count($current) > 1) throw new MediaException('MEDIA_BINDING_REPRESENTATIVE_SLOT_CONFLICT');
        $existing = $current[0] ?? null;
        if ($existing instanceof MediaUsage && $existing->selectionPolicy === 'PINNED' && $normalized['selection_source'] === 'SYSTEM_AUTO' && $existing->mediaId !== $media->canonicalId) throw new MediaException('PINNED_REPRESENTATIVE_PROTECTED');
        if ($existing instanceof MediaUsage && $existing->mediaId === $media->canonicalId) {
            if (!$this->usages instanceof MediaUsageUpdater) return ['usage' => $existing, 'previous_usage_id' => null, 'reconciliation' => 'KEEP'];
            $updated = $this->usages->update($this->usageFrom($existing, $normalized, $existing->revision));
            return ['usage' => $updated, 'previous_usage_id' => null, 'reconciliation' => 'UPDATE'];
        }
        if ($existing instanceof MediaUsage && !$this->usages instanceof MediaUsageUpdater) throw new MediaException('MEDIA_BINDING_USAGE_UPDATE_UNAVAILABLE');
        if ($existing instanceof MediaUsage) $this->usages->update(new MediaUsage($existing->usageId, $existing->mediaId, $existing->endpointType, $existing->endpointKey, 'gallery', $existing->sortOrder, $existing->altText, $existing->caption, $existing->keywordGroups, $existing->title, $existing->revision, $existing->placementKey, $existing->selectionSource, $existing->selectionPolicy, null));
        $candidate = new MediaUsage(UuidCodec::newV7(), $media->canonicalId, $targetType, $targetId, MediaUsageRoleRegistry::REPRESENTATIVE, 0, (string) $normalized['seo']['alt_text'], (string) $normalized['seo']['caption'], [], (string) $normalized['seo']['title'], 1, 'representative', (string) $normalized['selection_source'], (string) $normalized['selection_policy'], 'representative');
        try {
            $usage = $this->usages->create($candidate);
        } catch (\Throwable) {
            $raced = $this->usages->listByEndpoint($targetType, $targetId, MediaUsageRoleRegistry::REPRESENTATIVE);
            if (count($raced) !== 1 || !$raced[0] instanceof MediaUsage) throw new MediaException('MEDIA_BINDING_REPRESENTATIVE_SLOT_CONFLICT');
            $usage = $raced[0];
        }
        return ['usage' => $usage, 'previous_usage_id' => $existing?->usageId, 'reconciliation' => $existing instanceof MediaUsage ? 'REPLACE' : 'ADD'];
    }

    private function usageFrom(MediaUsage $current, array $request, int $revision): MediaUsage
    {
        return new MediaUsage($current->usageId, $current->mediaId, $current->endpointType, $current->endpointKey, $current->role, $current->sortOrder, (string) $request['seo']['alt_text'], (string) $request['seo']['caption'], $current->keywordGroups, (string) $request['seo']['title'], $revision, $current->placementKey, (string) $request['selection_source'], (string) $request['selection_policy'], 'representative');
    }

    /** @return array<string,mixed> */
    private function readback(string $mediaId, string $targetType, string $targetId, string $usageId): array
    {
        $usages = $this->usages->listByEndpoint($targetType, $targetId, MediaUsageRoleRegistry::REPRESENTATIVE);
        $matches = array_values(array_filter($usages, static fn (MediaUsage $item): bool => $item->usageId === $usageId && $item->mediaId === $mediaId));
        if (count($matches) !== 1 || count($usages) !== 1) throw new MediaException('MEDIA_BINDING_FINAL_READBACK_FAILED');
        return ['status' => 'verified', 'media_id' => $mediaId, 'target_type' => $targetType, 'target_id' => $targetId, 'usage_id' => $usageId, 'role' => MediaUsageRoleRegistry::REPRESENTATIVE, 'active_slot' => $matches[0]->activeSlot, 'active_representative_count' => count($usages)];
    }

    private function advance(MediaBindingOperation $operation, string $stage, string $status, ?string $mediaId = null, ?string $resultingUsageId = null, ?string $previousUsageId = null, array $metadata = []): MediaBindingOperation
    {
        $next = new MediaBindingOperation($operation->operationId, $operation->idempotencyKey, $operation->requestFingerprint, $mediaId ?? $operation->mediaId, $operation->targetType, $operation->targetId, $operation->requestedRole, $operation->selectionSource, $operation->selectionPolicy, $stage, $status, $resultingUsageId ?? $operation->resultingUsageId, $previousUsageId ?? $operation->previousUsageId, $operation->revision + 1, $status === 'COMPLETE' ? null : $operation->errorCode, $operation->createdAt, gmdate('Y-m-d H:i:s.u'), array_replace($operation->metadata, $metadata));
        return $this->operations === null ? $next : $this->operations->save($next, $operation->revision);
    }

    /** @return array<string,mixed> */
    private function usageArray(MediaUsage $usage): array
    {
        return ['id' => $usage->usageId, 'media_id' => $usage->mediaId, 'endpoint_type' => $usage->endpointType, 'endpoint_key' => $usage->endpointKey, 'role' => $usage->role, 'revision' => $usage->revision, 'selection_source' => $usage->selectionSource, 'selection_policy' => $usage->selectionPolicy, 'active_slot' => $usage->activeSlot, 'alt_text' => $usage->altText, 'caption' => $usage->caption, 'title' => $usage->title];
    }
}
