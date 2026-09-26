<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Contracts\Media\FirstPartyMediaTargetUrlResolver;
use NHK\Core\Domain\Media\{MediaException, MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Compiles user-owned URL intent into the canonical governed MediaUsage plan. */
final class MediaEnrichmentIntentCompiler
{
    public function __construct(
        private MediaBindingService $media,
        private MediaUsageRepository $usages,
        private MediaTargetNormalizer $targets,
        private $targetUrlResolver,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function compile(array $input): array
    {
        $input = $this->normalizeNaturalRepresentativeCommand($input);
        if (strtoupper(trim((string) ($input['intent'] ?? ''))) !== 'MEDIA_ENRICHMENT') return $input;
        $operations = array_values(array_filter((array) ($input['media_operations'] ?? []), 'is_array'));
        if ($operations === []) return $input;

        $compiled = [];
        foreach ($operations as $index => $operation) {
            $kind = strtolower(trim((string) ($operation['operation'] ?? '')));
            if ($kind === 'representative_bind') {
                $target = $this->canonicalTarget((array) ($operation['target'] ?? []));
                if (($target['type'] ?? '') === 'wp_post') {
                    $operation['operation'] = 'set_featured';
                    $operation['role'] = MediaUsageRoleRegistry::FEATURED_PRIMARY;
                    $kind = 'set_featured';
                } else {
                    $compiled[] = $this->compileRepresentative($operation, $target, $index, (string) ($input['idempotency_key'] ?? ''));
                    continue;
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
        if (isset($target['url']) || strtolower(trim((string) ($target['type'] ?? ''))) === 'wp_post') $target = $this->canonicalTarget($target);
        $operation['media'] = $media;
        unset($operation['media_ref']);
        $operation['target'] = $target;
        $operation['idempotency_key'] = trim((string) ($operation['idempotency_key'] ?? '')) ?: $requestKey . ':media-operation:' . $index;
        return $operation;
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function canonicalTarget(array $target): array
    {
        $url = trim((string) ($target['url'] ?? ''));
        if ($url === '') {
            $normalized = $this->targets->normalizeRequestTarget($target);
            unset($normalized['revision']);
            return $normalized;
        }
        if (!is_callable($this->targetUrlResolver) && !$this->targetUrlResolver instanceof FirstPartyMediaTargetUrlResolver) throw new MediaException('MEDIA_TARGET_URL_REQUIRED');
        try {
            $resolved = $this->targetUrlResolver instanceof FirstPartyMediaTargetUrlResolver
                ? $this->targetUrlResolver->resolve($url)
                : ($this->targetUrlResolver)($url);
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
        // Post revision is an execution snapshot, not part of the stable user
        // intent fingerprint. MediaUsage CAS is supplied separately below.
        unset($normalized['revision']);
        return $normalized;
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function compileRepresentative(array $operation, array $target, int $index, string $requestKey): array
    {
        $mediaRef = is_array($operation['media_ref'] ?? null) ? $operation['media_ref'] : (array) ($operation['media'] ?? []);
        $media = $this->media->resolveMediaReference($mediaRef);
        $role = MediaUsageRoleRegistry::REPRESENTATIVE;
        $placement = $role;
        $current = array_values(array_filter(
            $this->usages->listByEndpoint((string) $target['type'], (string) $target['id'], $role),
            static fn (mixed $usage): bool => $usage instanceof MediaUsage && $usage->activeSlot !== 'retired',
        ));
        if (count($current) > 1) throw new MediaException('MEDIA_USAGE_SLOT_AMBIGUOUS');
        $existing = $current[0] ?? null;
        $base = [
            'idempotency_key' => trim((string) ($operation['idempotency_key'] ?? '')) ?: $requestKey . ':media-operation:' . $index,
            'media' => ['id' => $media->canonicalId], 'target' => $target, 'role' => $role,
            'placement_key' => $placement, 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED',
            'seo' => is_array($operation['seo'] ?? null) ? $operation['seo'] : [],
        ];
        if ($existing instanceof MediaUsage && $existing->mediaId === $media->canonicalId) return $base + ['operation' => 'keep', 'usage_id' => $existing->usageId, 'expected_usage_revision' => $existing->revision];
        if ($existing instanceof MediaUsage) return $base + ['operation' => 'representative_bind', 'usage_id' => $existing->usageId, 'expected_usage_revision' => $existing->revision];
        return $base + ['operation' => 'representative_bind'];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeNaturalRepresentativeCommand(array $input): array
    {
        if ((array) ($input['media_operations'] ?? []) !== []) return $input;
        $text = trim((string) ($input['text'] ?? $input['content'] ?? ''));
        [$mediaUrl, $targetUrl] = $this->naturalRepresentativeLocators($text);
        if ($mediaUrl === null || $targetUrl === null) return $input;
        $input['intent'] = 'MEDIA_ENRICHMENT';
        $input['media_operations'] = [[
            'operation' => 'representative_bind', 'media_ref' => ['url' => $mediaUrl], 'target' => ['url' => $targetUrl], 'role' => 'representative',
        ]];
        return $input;
    }

    /** @return array{0:?string,1:?string} */
    private function naturalRepresentativeLocators(string $text): array
    {
        $patterns = [
            ['~^Ảnh\s+đại\s+diện\s+của\s+(https://\S+)\s+thay\s+bằng\s+(https://\S+)\s*[.!?]?$~iu', 2, 1],
            ['~^Thay\s+ảnh\s+đại\s+diện\s+của\s+(https://\S+)\s+bằng\s+(https://\S+)\s*[.!?]?$~iu', 2, 1],
            ['~^Dùng\s+(https://\S+)\s+làm\s+ảnh\s+đại\s+diện\s+cho\s+(https://\S+)\s*[.!?]?$~iu', 1, 2],
            ['~^Dùng\s+ảnh\s+(https://\S+)\s+làm\s+đại\s+diện\s+cho\s+(https://\S+)\s*[.!?]?$~iu', 1, 2],
        ];
        foreach ($patterns as [$pattern, $mediaIndex, $targetIndex]) {
            if (preg_match($pattern, $text, $matches) !== 1) continue;
            return [rtrim($matches[$mediaIndex], '.,!?'), rtrim($matches[$targetIndex], '.,!?')];
        }
        return [null, null];
    }
}
