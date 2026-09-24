<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

/**
 * Completes MediaEnrichment only after the canonical usage and every required
 * consumer read model have converged.
 */
final class MediaEnrichmentCompletionPolicy
{
    /** @param callable(string,string):?array<string,mixed> $capability @param callable(string,string,string,string):array<string,mixed> $projection @param callable(string,string,string,string):array<string,mixed> $public */
    public function __construct(private $capability, private $projection, private $public)
    {
    }

    /** @return array<string,mixed> */
    public function verify(array $media): array
    {
        $bindings = array_values(array_filter((array) ($media['bindings'] ?? []), 'is_array'));
        $usages = array_values(array_filter((array) ($media['media_usage'] ?? $media['canonical_readback']['media_usage'] ?? []), 'is_array'));
        $receipts = $bindings !== [] ? $bindings : $usages;
        if ($receipts === []) return ['status' => 'unavailable', 'reason' => 'MEDIA_USAGE_CANONICAL_READBACK_UNVERIFIED'];

        $projection = [];
        $public = [];
        foreach ($receipts as $receipt) {
            $readback = is_array($receipt['readback'] ?? null) ? $receipt['readback'] : $receipt;
            $mediaId = trim((string) ($readback['media_id'] ?? $receipt['media_id'] ?? ''));
            $usageId = trim((string) ($readback['usage_id'] ?? $receipt['usage_id'] ?? ''));
            $type = strtolower(trim((string) ($readback['target_type'] ?? $receipt['endpoint_type'] ?? '')));
            $target = trim((string) ($readback['target_id'] ?? $receipt['endpoint_key'] ?? ''));
            $role = trim((string) ($readback['role'] ?? $receipt['role'] ?? ''));
            if (($receipt['status'] ?? 'COMPLETE') !== 'COMPLETE'
                && strtolower(trim((string) ($readback['status'] ?? ''))) !== 'verified') {
                return ['status' => 'unavailable', 'reason' => 'MEDIA_USAGE_CANONICAL_READBACK_UNVERIFIED'];
            }
            if (strtolower(trim((string) ($readback['status'] ?? 'verified'))) !== 'verified' || $mediaId === '' || $usageId === '' || $type === '' || $target === '') {
                return ['status' => 'unavailable', 'reason' => 'MEDIA_USAGE_CANONICAL_READBACK_UNVERIFIED'];
            }
            $capability = ($this->capability)($type, $target);
            if (!is_array($capability)) return ['status' => 'unavailable', 'reason' => 'OWNER_MEDIA_CAPABILITY_UNAVAILABLE'];

            $projectionReadback = ($this->projection)($type, $target, $mediaId, $role);
            $projectionOk = ($capability['projection_required'] ?? true) !== true || (($projectionReadback['status'] ?? '') === 'verified' && ($projectionReadback['media_id'] ?? '') === $mediaId);
            $projection[] = ['status' => $projectionOk ? 'verified' : 'stale', 'target_type' => $type, 'target_id' => $target, 'media_id' => $mediaId] + $projectionReadback;
            if (!$projectionOk) return ['status' => 'unavailable', 'reason' => 'PROJECTION_READBACK_STALE', 'projection' => $projection];

            if (($capability['public_required'] ?? false) === true || ($capability['frontend_required'] ?? false) === true) {
                $publicReadback = ($this->public)($type, $target, $mediaId, $role);
                $publicOk = ($publicReadback['status'] ?? '') === 'verified' && ($publicReadback['media_id'] ?? '') === $mediaId;
                $public[] = ['status' => $publicOk ? 'verified' : 'stale', 'target_type' => $type, 'target_id' => $target, 'media_id' => $mediaId] + $publicReadback;
                if (!$publicOk) return ['status' => 'unavailable', 'reason' => ($capability['frontend_required'] ?? false) === true ? 'FRONTEND_READBACK_STALE' : 'PUBLIC_SURFACE_READBACK_STALE', 'projection' => $projection, 'public' => $public];
            }
        }
        return ['status' => 'verified', 'completion' => 'PUBLIC_COMPLETE', 'canonical_status' => 'CANONICAL_COMPLETE', 'projected_status' => 'PROJECTED_COMPLETE', 'projection' => $projection, 'public' => $public, 'canonical_owner' => 'MediaUsage'];
    }
}
