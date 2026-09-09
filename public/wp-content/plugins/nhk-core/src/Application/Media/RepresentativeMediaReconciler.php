<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository, MediaUsageUpdater};
use NHK\Core\Domain\Media\{Media, MediaUsage, MediaUsageRoleRegistry};

/**
 * Deterministic presentation-only representative reconciliation.
 *
 * It never creates a factual relation. A candidate must be explicitly scoped
 * to the endpoint by the caller; the old usage is retained and demoted when a
 * mutating usage adapter is available.
 */
final class RepresentativeMediaReconciler
{
    public function __construct(
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private MediaUsageRepository $usages,
        private MediaService $mediaService,
    ) {}

    /** @param list<array<string,mixed>> $candidates @return array<string,mixed> */
    public function reconcile(string $endpointType, string $endpointKey, array $candidates): array
    {
        $eligible = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || ($candidate['scope_justified'] ?? false) !== true) continue;
            $mediaId = trim((string) ($candidate['media_id'] ?? ''));
            $media = $mediaId !== '' ? $this->media->findByCanonicalId($mediaId) : null;
            if (!$media instanceof Media || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder() || $this->assets->listByMediaId($mediaId) === []) continue;
            $candidate['media_id'] = $mediaId;
            $candidate['stable_key'] = $media->stableKey;
            $candidate['score'] = $this->score($candidate);
            $eligible[] = $candidate;
        }
        usort($eligible, static fn (array $left, array $right): int => ($right['score'] <=> $left['score']) ?: strcmp((string) $left['stable_key'], (string) $right['stable_key']));
        $best = $eligible[0] ?? null;
        if ($best === null) return ['status' => 'NO_SUITABLE_CANDIDATE', 'actions' => [], 'candidates' => []];

        $current = $this->usages->listByEndpoint($endpointType, $endpointKey, MediaUsageRoleRegistry::REPRESENTATIVE)[0] ?? null;
        if ($current instanceof MediaUsage && $current->mediaId === $best['media_id']) return ['status' => 'KEPT', 'media_id' => $best['media_id'], 'score' => $best['score'], 'actions' => [['action' => 'KEEP', 'usage_id' => $current->usageId]]];
        $actions = [];
        if ($current instanceof MediaUsage) {
            if (!$this->usages instanceof MediaUsageUpdater) return ['status' => 'OWNER_REVIEW_REQUIRED', 'media_id' => $current->mediaId, 'candidate_media_id' => $best['media_id'], 'actions' => [['action' => 'DEMOTE', 'usage_id' => $current->usageId]]];
            $this->usages->update(new MediaUsage($current->usageId, $current->mediaId, $current->endpointType, $current->endpointKey, MediaUsageRoleRegistry::TECHNICAL_DETAIL, $current->sortOrder, $current->altText, $current->caption, $current->keywordGroups));
            $actions[] = ['action' => 'DEMOTE', 'usage_id' => $current->usageId, 'to_role' => MediaUsageRoleRegistry::TECHNICAL_DETAIL];
        }
        $usage = $this->mediaService->addUsage($best['media_id'], $endpointType, $endpointKey, MediaUsageRoleRegistry::REPRESENTATIVE, 0, (string) ($best['alt_text'] ?? ''), (string) ($best['caption'] ?? ''));
        $actions[] = ['action' => $current instanceof MediaUsage ? 'PROMOTE' : 'ADD', 'usage_id' => $usage->usageId, 'media_id' => $usage->mediaId];
        return ['status' => $current instanceof MediaUsage ? 'PROMOTED' : 'ADDED', 'media_id' => $usage->mediaId, 'score' => $best['score'], 'actions' => $actions, 'candidates' => $eligible];
    }

    /** @param array<string,mixed> $candidate */
    private function score(array $candidate): int
    {
        return ((int) ($candidate['semantic_specificity'] ?? 0) * 100)
            + ((int) ($candidate['visual_subject_coverage'] ?? 0) * 20)
            + ((int) ($candidate['technical_usefulness'] ?? 0) * 10)
            + ((int) ($candidate['clarity_resolution'] ?? 0) * 5)
            + ((int) ($candidate['provenance_confidence'] ?? 0) * 3)
            - ((int) ($candidate['obstruction'] ?? 0) * 2);
    }
}
