<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Application\Entity\{EntityMediaProjection, PublicEntityEligibilityPolicy};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Media\{MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;

/** Machine-verifies the public read model used by Classification cards. */
final class MediaEnrichmentFrontendReadbackVerifier
{
    public function __construct(
        private AuthorityRepository $authority,
        private EntityTypeRegistry $types,
        private PublicEntityEligibilityPolicy $eligibility,
        private EntityMediaProjection $projection,
        private MediaUsageRepository $usages,
    ) {}

    /** @return array<string,mixed> */
    public function verify(string $targetType, string $targetId, string $expectedMediaId): array
    {
        $targetType = strtolower(trim($targetType));
        $targetId = trim($targetId);
        $expectedMediaId = trim($expectedMediaId);
        if (!$this->types->has($targetType) || !UuidCodec::isValid($targetId) || !UuidCodec::isValid($expectedMediaId)) return $this->failed('PUBLIC_TARGET_REFERENCE_INVALID');
        $target = $this->authority->findByCanonicalId($targetId);
        $decision = $this->eligibility->evaluate($target);
        if ($target === null || !$decision->eligible) return $this->failed('PUBLIC_TARGET_NOT_READABLE');

        $active = array_values(array_filter($this->usages->listByEndpoint($targetType, $targetId, MediaUsageRoleRegistry::REPRESENTATIVE), static fn (mixed $usage): bool => $usage instanceof MediaUsage && $usage->activeSlot !== 'retired' && ($usage->activeSlot === null || $usage->activeSlot === 'representative')));
        if (count($active) !== 1 || $active[0]->mediaId !== $expectedMediaId) return $this->failed('CANONICAL_REPRESENTATIVE_MISMATCH', ['active_representative_count' => count($active)]);

        $projection = $this->projection->forEntity($targetType, $targetId);
        $representative = is_array($projection['representative'] ?? null) ? $projection['representative'] : null;
        if ($representative === null || (string) ($representative['media_id'] ?? '') !== $expectedMediaId) return $this->failed('PUBLIC_REPRESENTATIVE_PROJECTION_MISMATCH');
        return ['status' => 'verified', 'target_type' => $targetType, 'target_id' => $targetId, 'media_id' => $expectedMediaId, 'active_representative_count' => 1, 'projection' => ['media_id' => $representative['media_id'], 'asset_id' => $representative['asset_id'] ?? '', 'url' => $representative['url'] ?? '']];
    }

    /** @return array<string,mixed> */
    private function failed(string $reason, array $extra = []): array
    {
        return ['status' => 'unavailable', 'reason' => $reason] + $extra;
    }
}
