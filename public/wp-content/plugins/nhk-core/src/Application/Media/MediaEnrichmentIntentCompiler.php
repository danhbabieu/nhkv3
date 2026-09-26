<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Domain\Media\{MediaException, MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Compiles user-owned URL intent into the canonical governed MediaUsage plan. */
final class MediaEnrichmentIntentCompiler
{
    public function __construct(
        private MediaBindingService $media,
        private MediaUsageRepository $usages,
        private MediaTargetNormalizer $targets,
        private $postUrlResolver,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function compile(array $input): array
    {
        if (strtoupper(trim((string) ($input['intent'] ?? ''))) !== 'MEDIA_ENRICHMENT') return $input;
        $operations = array_values(array_filter((array) ($input['media_operations'] ?? []), 'is_array'));
        if ($operations === []) return $input;

        $compiled = [];
        foreach ($operations as $index => $operation) {
            $kind = strtolower(trim((string) ($operation['operation'] ?? '')));
            if ($kind === 'representative_bind') {
                $representativeTarget = $this->canonicalTarget((array) ($operation['target'] ?? []));
                $operation['target'] = $representativeTarget;
                if (($representativeTarget['type'] ?? '') === 'wp_post') {
                    // Natural-language "use this as the representative image"
                    // maps to native WordPress featured media for an Article.
                    $operation['operation'] = 'set_featured';
                    $operation['role'] = MediaUsageRoleRegistry::FEATURED_PRIMARY;
                    $kind = 'set_featured';
                }
            }
            if ($kind !== 'set_featured') {
                $compiled[] = $this->canonicalOperation($operation, $index, (string) ($input['idempotency_key'] ?? ''));
                continue;
            }
            $role = strtolower(trim((string) ($operation['role'] ?? MediaUsageRoleRegistry::FEATURED_PRIMARY)));
            if ($role !== MediaUsageRoleRegistry::FEATURED_PRIMARY) throw new MediaException('MEDIA_FEATURED_ROLE_INVALID');
            $target = $this->canonicalTarget((array) ($operation['target'] ?? []));
            if (($target['type'] ?? '') !== 'wp_post') throw new MediaException('MEDIA_FEATURED_TARGET_INVALID');
            $mediaRef = is_array($operation['media_ref'] ?? null) ? $operation['media_ref'] : (array) ($operation['media'] ?? []);
            $media = $this->media->resolveMediaReference($mediaRef);
            $canonicalPlacement = MediaUsageRoleRegistry::FEATURED_PRIMARY;
            $current = array_values(array_filter(
                $this->usages->listByEndpoint('wp_post', (string) $target['id']),
                static fn (mixed $usage): bool => $usage instanceof MediaUsage
                    && $usage->activeSlot !== 'retired'
                    && $usage->role === $role
                    && in_array($usage->placementKey, ['', $canonicalPlacement], true),
            ));
            if (count($current) > 1) throw new MediaException('MEDIA_USAGE_SLOT_AMBIGUOUS');
            $existing = $current[0] ?? null;
            $base = [
                'idempotency_key' => trim((string) ($operation['idempotency_key'] ?? '')) ?: trim((string) ($input['idempotency_key'] ?? '')) . ':media-operation:' . $index,
                'media' => ['id' => $media->canonicalId],
                'target' => $target,
                'role' => $role,
                'placement_key' => $canonicalPlacement,
                'selection_source' => 'USER_EXPLICIT',
                'selection_policy' => 'PINNED',
                'seo' => is_array($operation['seo'] ?? null) ? $operation['seo'] : [],
            ];
            if ($existing instanceof MediaUsage && $existing->mediaId === $media->canonicalId && $existing->placementKey === $canonicalPlacement) {
                $compiled[] = $base + ['operation' => 'keep', 'usage_id' => $existing->usageId, 'expected_usage_revision' => $existing->revision];
            } elseif ($existing instanceof MediaUsage) {
                $compiled[] = $base + ['operation' => 'replace', 'usage_id' => $existing->usageId, 'expected_usage_revision' => $existing->revision];
            } else {
                $compiled[] = $base + ['operation' => 'add'];
            }
        }
        $input['media_operations'] = $compiled;
        $input['_nhk_exact_media_operations'] = true;
        return $input;
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function canonicalOperation(array $operation, int $index, string $requestKey): array
    {
        $media = is_array($operation['media'] ?? null) ? $operation['media'] : (array) ($operation['media_ref'] ?? []);
        if (isset($media['url'])) $media = ['id' => $this->media->resolveMediaReference($media)->canonicalId];
        $target = (array) ($operation['target'] ?? []);
        if (isset($target['url'])) $target = $this->resolveUrlTarget($target);
        elseif (strtolower(trim((string) ($target['type'] ?? ''))) === 'wp_post') $target = $this->targets->normalizeRequestTarget($target);
        $operation['media'] = $media;
        unset($operation['media_ref']);
        $operation['target'] = $target;
        $operation['idempotency_key'] = trim((string) ($operation['idempotency_key'] ?? '')) ?: $requestKey . ':media-operation:' . $index;
        return $operation;
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function canonicalTarget(array $target): array
    {
        if (isset($target['url'])) return $this->resolveUrlTarget($target);
        $normalized = $this->targets->normalizeRequestTarget($target);
        unset($normalized['revision']);
        return $normalized;
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function resolveUrlTarget(array $target): array
    {
        $url = trim((string) ($target['url'] ?? ''));
        if ($url === '' || !is_callable($this->postUrlResolver)) throw new MediaException('MEDIA_TARGET_URL_REQUIRED');
        try {
            $resolved = ($this->postUrlResolver)($url);
        } catch (\Throwable $error) {
            $code = trim($error->getMessage());
            throw new MediaException($code !== '' ? $code : 'MEDIA_TARGET_NOT_FOUND');
        }
        if (!is_array($resolved)) throw new MediaException('MEDIA_TARGET_NOT_FOUND');
        $type = strtolower(trim((string) ($resolved['type'] ?? '')));
        $id = trim((string) ($resolved['id'] ?? ''));
        if ($type === '' || $id === '') throw new MediaException('MEDIA_TARGET_NOT_FOUND');

        $requestedType = strtolower(trim((string) ($target['type'] ?? '')));
        if ($requestedType !== '' && $requestedType !== $type) throw new MediaException('MEDIA_TARGET_TYPE_MISMATCH');

        $normalized = $this->targets->normalizeRequestTarget(['type' => $type, 'id' => $id]);
        // Target revision is an execution snapshot, not part of stable user
        // intent. The owning mutation boundary supplies its own CAS/read-back.
        unset($normalized['revision']);
        return $normalized;
    }
}
