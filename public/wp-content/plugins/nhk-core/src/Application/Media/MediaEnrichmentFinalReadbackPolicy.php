<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

/**
 * Final-readback policy for MEDIA_ENRICHMENT.
 *
 * MediaUsage is the canonical owner for this intent. A public/frontend
 * projection is useful evidence but is not an additional owner requirement.
 */
final class MediaEnrichmentFinalReadbackPolicy
{
    /** @return array<string,mixed> */
    public function verify(array $media): array
    {
        $bindings = array_values(array_filter((array) ($media['bindings'] ?? []), 'is_array'));
        if ($bindings !== []) {
            foreach ($bindings as $binding) {
                $readback = is_array($binding['readback'] ?? null) ? $binding['readback'] : [];
                if (strtoupper(trim((string) ($binding['status'] ?? ''))) !== 'COMPLETE'
                    || strtolower(trim((string) ($readback['status'] ?? ''))) !== 'verified'
                    || trim((string) ($readback['media_id'] ?? '')) === ''
                    || trim((string) ($readback['usage_id'] ?? '')) === ''
                    || trim((string) ($readback['target_type'] ?? '')) === ''
                    || trim((string) ($readback['target_id'] ?? '')) === '') {
                    return ['status' => 'unavailable', 'reason' => 'MEDIA_USAGE_CANONICAL_READBACK_UNVERIFIED'];
                }
            }
            return [
                'status' => 'verified',
                'frontend_verified' => null,
                'media_bindings' => $bindings,
                'canonical_owner' => 'MediaUsage',
                'article_owner' => 'NOT_REQUIRED',
            ];
        }

        $mediaReadback = array_values(array_filter((array) ($media['media_readback'] ?? []), 'is_array'));
        $usageReadback = array_values(array_filter((array) ($media['canonical_readback']['media_usage'] ?? []), 'is_array'));
        if (strtoupper(trim((string) ($media['status'] ?? ''))) !== 'RECONCILED'
            || ($media['media_complete'] ?? false) !== true
            || $mediaReadback === []
            || $usageReadback === []
            || count(array_filter($mediaReadback, static fn (array $item): bool => strtolower(trim((string) ($item['status'] ?? ''))) === 'verified' && trim((string) ($item['media_id'] ?? '')) !== '')) !== count($mediaReadback)
            || count(array_filter($usageReadback, static fn (array $item): bool => strtolower(trim((string) ($item['status'] ?? ''))) === 'verified' && trim((string) ($item['usage_id'] ?? '')) !== '' && trim((string) ($item['media_id'] ?? '')) !== '')) !== count($usageReadback)) {
            return ['status' => 'unavailable', 'reason' => 'MEDIA_USAGE_CANONICAL_READBACK_UNVERIFIED'];
        }

        return [
            'status' => 'verified',
            'frontend_verified' => null,
            'media_readback' => $mediaReadback,
            'media_usage' => $usageReadback,
            'canonical_owner' => 'MediaUsage',
            'article_owner' => 'NOT_REQUIRED',
        ];
    }
}
