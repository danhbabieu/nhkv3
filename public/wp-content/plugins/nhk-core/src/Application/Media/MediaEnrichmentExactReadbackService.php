<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaUsageRepository, WordPressArticleMediaAdapter};
use NHK\Core\Domain\Media\MediaUsage;

/**
 * Verifies the canonical result of compiled MediaEnrichment operations.
 *
 * This is deliberately read-only. Governance remains the only mutation
 * boundary; this service only decides whether the resulting state is safe to
 * report as reconciled.
 */
final class MediaEnrichmentExactReadbackService
{
    public function __construct(
        private MediaUsageRepository $usages,
        private WordPressArticleMediaAdapter $wordpress,
        /** @param callable(string,string):(?array<string,mixed>)|null $authorityProjection */
        private $authorityProjection = null,
    ) {}

    /** @param list<array<string,mixed>> $operations @param list<array<string,mixed>> $governedResults */
    public function verify(array $operations, array $governedResults): array
    {
        $governedResults = array_values(array_filter($governedResults, static fn (mixed $result): bool => is_array($result) && strtolower(trim((string) ($result['operation'] ?? ''))) !== 'keep' && strtoupper(trim((string) ($result['status'] ?? ''))) !== 'PENDING_READBACK'));
        $readback = [];
        $blockers = [];
        $governedIndex = 0;

        foreach ($operations as $operation) {
            $name = strtolower(trim((string) ($operation['operation'] ?? '')));
            if ($name === '') {
                $blockers[] = 'MEDIA_OPERATION_INVALID';
                continue;
            }

            if ($name !== 'keep') {
                $governed = $governedResults[$governedIndex] ?? null;
                $governedIndex++;
                if (!is_array($governed) || !$this->governanceApplied($governed)) {
                    $blockers[] = is_array($governed) && strtolower((string) ($governed['status'] ?? '')) === 'blocked'
                        ? 'GOVERNANCE_OPERATION_BLOCKED'
                        : 'GOVERNANCE_OPERATION_NOT_APPLIED';
                }
            }

            $target = is_array($operation['target'] ?? null) ? $operation['target'] : [];
            $targetType = strtolower(trim((string) ($target['type'] ?? '')));
            $targetId = trim((string) ($target['id'] ?? ''));
            $role = trim((string) ($operation['role'] ?? ''));
            $placement = trim((string) ($operation['placement_key'] ?? ''));
            $mediaId = trim((string) (($operation['media']['id'] ?? $operation['media_id'] ?? '')));
            $candidates = $targetType !== '' && $targetId !== '' && $role !== ''
                ? $this->usages->listByEndpoint($targetType, $targetId, $role)
                : [];
            $matching = array_values(array_filter($candidates, static function (mixed $usage) use ($mediaId, $placement): bool {
                return $usage instanceof MediaUsage
                    && $usage->activeSlot !== 'retired'
                    && $usage->mediaId === $mediaId
                    && $usage->placementKey === $placement;
            }));

            $usage = count($matching) === 1 ? $matching[0] : null;
            $expectedUsageId = trim((string) ($operation['usage_id'] ?? ''));
            $expectedRevision = (int) ($operation['expected_usage_revision'] ?? 0);
            if (!$usage instanceof MediaUsage) {
                $blockers[] = $name === 'keep' ? 'MEDIA_USAGE_CAS_CONFLICT' : 'MEDIA_USAGE_READBACK_MISMATCH';
                continue;
            }
            if ($name === 'keep' && ($expectedUsageId === '' || $usage->usageId !== $expectedUsageId || $expectedRevision < 1 || $usage->revision !== $expectedRevision)) {
                $blockers[] = 'MEDIA_USAGE_CAS_CONFLICT';
                continue;
            }

            $entry = [
                'status' => 'verified',
                'operation' => strtoupper($name),
                'media_id' => $usage->mediaId,
                'usage_id' => $usage->usageId,
                'revision' => $usage->revision,
                'target_type' => $usage->endpointType,
                'target_id' => $usage->endpointKey,
                'role' => $usage->role,
                'placement_key' => $usage->placementKey,
                'active' => $usage->activeSlot !== 'retired',
            ];

            if ($name === 'replace' && $expectedUsageId !== '') {
                $old = array_values(array_filter($candidates, static fn (mixed $candidate): bool => $candidate instanceof MediaUsage && $candidate->usageId === $expectedUsageId))[0] ?? null;
                if (!$old instanceof MediaUsage || $old->activeSlot !== 'retired') {
                    $blockers[] = 'MEDIA_USAGE_OLD_NOT_RETIRED';
                    continue;
                }
                $entry['replaced_usage'] = ['usage_id' => $old->usageId, 'active_slot' => $old->activeSlot, 'revision' => $old->revision];
            }

            if ($role === 'featured_primary' && $targetType === 'wp_post') {
                $postId = $this->postId($targetId);
                $projection = $postId > 0 ? $this->wordpress->read($postId) : [];
                if ($postId < 1 || (string) ($projection['featured_media_id'] ?? '') !== $usage->mediaId) {
                    $blockers[] = 'FEATURED_PROJECTION_READBACK_MISMATCH';
                    continue;
                }
                $entry['featured_projection'] = ['status' => 'verified', 'media_id' => $usage->mediaId, 'post_id' => $postId];
            }
            if ($role === 'representative' && $targetType !== 'wp_post') {
                if (!is_callable($this->authorityProjection)) {
                    $blockers[] = 'REPRESENTATIVE_PROJECTION_READBACK_UNAVAILABLE';
                    continue;
                }
                $projection = ($this->authorityProjection)($targetType, $targetId);
                $representative = is_array($projection) && is_array($projection['representative'] ?? null)
                    ? $projection['representative']
                    : null;
                if ($representative === null || (string) ($representative['media_id'] ?? '') !== $usage->mediaId) {
                    $blockers[] = 'REPRESENTATIVE_PROJECTION_READBACK_MISMATCH';
                    continue;
                }
                $entry['representative_projection'] = ['status' => 'verified', 'media_id' => $usage->mediaId, 'target_type' => $targetType, 'target_id' => $targetId];
            }
            $readback[] = $entry;
        }

        if (count($readback) !== count($operations)) $blockers[] = 'MEDIA_USAGE_READBACK_INCOMPLETE';
        $blockers = array_values(array_unique($blockers));
        return ['status' => $blockers === [] ? 'RECONCILED' : 'PARTIAL', 'complete' => $blockers === [], 'media_complete' => $blockers === [], 'media_usage' => $readback, 'blockers' => $blockers];
    }

    /** @param array<string,mixed> $result */
    private function governanceApplied(array $result): bool
    {
        $status = strtolower(trim((string) ($result['status'] ?? '')));
        return in_array($status, ['applied', 'published'], true)
            && is_array($result['canonical_readback'] ?? null);
    }

    private function postId(string $targetId): int
    {
        $parts = explode(':', $targetId, 2);
        return count($parts) === 2 && ctype_digit($parts[1]) ? (int) $parts[1] : 0;
    }
}
