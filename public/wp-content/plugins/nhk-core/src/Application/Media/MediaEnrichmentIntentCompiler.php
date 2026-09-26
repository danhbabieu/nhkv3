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
            if ($kind !== 'set_featured') {
                $compiled[] = $this->canonicalOperation($operation, $index, (string) ($input['idempotency_key'] ?? ''));
                continue;
            }
            $role = strtolower(trim((string) ($operation['role'] ?? MediaUsageRoleRegistry::FEATURED_PRIMARY)));
            if ($role !== MediaUsageRoleRegistry::FEATURED_PRIMARY) throw new MediaException('MEDIA_FEATURED_ROLE_INVALID');
            $target = $this->resolvePostTarget((array) ($operation['target'] ?? []));
            $mediaRef = is_array($operation['media_ref'] ?? null) ? $operation['media_ref'] : (array) ($operation['media'] ?? []);
            $media = $this->media->resolveMediaReference($mediaRef);
            $current = array_values(array_filter(
                $this->usages->listByEndpoint('wp_post', (string) $target['id']),
                static fn (mixed $usage): bool => $usage instanceof MediaUsage
                    && $usage->activeSlot !== 'retired'
                    && $usage->role === $role
                    && $usage->placementKey === MediaUsageRoleRegistry::FEATURED_PRIMARY,
            ));
            if (count($current) > 1) throw new MediaException('MEDIA_USAGE_SLOT_AMBIGUOUS');
            $existing = $current[0] ?? null;
            $base = [
                'idempotency_key' => trim((string) ($operation['idempotency_key'] ?? '')) ?: trim((string) ($input['idempotency_key'] ?? '')) . ':media-operation:' . $index,
                'media' => ['id' => $media->canonicalId],
                'target' => $target,
                'role' => $role,
                'placement_key' => MediaUsageRoleRegistry::FEATURED_PRIMARY,
                'selection_source' => 'USER_EXPLICIT',
                'selection_policy' => 'PINNED',
                'seo' => is_array($operation['seo'] ?? null) ? $operation['seo'] : [],
            ];
            if ($existing instanceof MediaUsage && $existing->mediaId === $media->canonicalId) {
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
        if (isset($target['url'])) $target = $this->resolvePostTarget($target);
        elseif (strtolower(trim((string) ($target['type'] ?? ''))) === 'wp_post') $target = $this->targets->normalizeRequestTarget($target);
        $operation['media'] = $media;
        unset($operation['media_ref']);
        $operation['target'] = $target;
        $operation['idempotency_key'] = trim((string) ($operation['idempotency_key'] ?? '')) ?: $requestKey . ':media-operation:' . $index;
        return $operation;
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function resolvePostTarget(array $target): array
    {
        $url = trim((string) ($target['url'] ?? ''));
        if ($url === '' || !is_callable($this->postUrlResolver)) throw new MediaException('MEDIA_TARGET_URL_REQUIRED');
        $resolved = ($this->postUrlResolver)($url);
        if (!is_array($resolved)) throw new MediaException('MEDIA_TARGET_NOT_FOUND');
        $type = strtolower(trim((string) ($resolved['type'] ?? '')));
        $id = trim((string) ($resolved['id'] ?? ''));
        if ($type !== 'wp_post' || preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $id) !== 1) throw new MediaException('MEDIA_TARGET_NOT_FOUND');
        $normalized = $this->targets->normalizeRequestTarget(['type' => 'wp_post', 'id' => $id]);
        // Post revision is an execution snapshot, not part of the stable user
        // intent fingerprint. MediaUsage CAS is supplied separately below.
        unset($normalized['revision']);
        return $normalized;
    }
}
