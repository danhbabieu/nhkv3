<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, MediaUsageRepository};
use NHK\Core\Domain\Media\{Media, MediaSeoBlueprint};

/** Selects only semantically eligible Article media before technical ranking. */
final class ArticleMediaCandidateSelector
{
    public function __construct(
        private MediaRepository $media,
        private MediaAssetRepository $assets,
        private MediaUsageRepository $usages,
        private SemanticSuitabilityPolicy $suitability,
    ) {}

    /** @param list<string> $usedMediaIds @return array{media:?Media,candidates:list<array<string,mixed>>,diagnostics:list<array<string,mixed>>} */
    public function select(MediaSeoBlueprint $blueprint, array $usedMediaIds = [], bool $allowReuseOfUsed = false): array
    {
        $subjectIds = $this->subjectIds($blueprint);
        $candidates = [];
        foreach ($this->media->list() as $media) {
            if (!$media instanceof Media || (!$allowReuseOfUsed && in_array($media->canonicalId, $usedMediaIds, true))) continue;
            $assets = $this->assets->listByMediaId($media->canonicalId);
            $assessment = $this->suitability->evaluateMedia(
                $media,
                $assets,
                ['subject_ids' => $subjectIds, 'subject_context' => $blueprint->subjectContext],
                'SYSTEM_AUTO',
                $blueprint->slot,
            );
            $publicAsset = (new PublicMediaAssetSelector())->canonical($assets);
            $eligible = ($assessment['eligible'] ?? $assessment['valid_for_completeness'] ?? false) === true
                && $media->active
                && $media->readiness === 'ready'
                && !$media->isSystemPlaceholder()
                && $publicAsset !== null;
            $score = $this->score($media, $blueprint, $assessment, $publicAsset !== null, in_array($media->canonicalId, $usedMediaIds, true));
            $candidates[] = [
                'media_id' => $media->canonicalId,
                'stable_key' => $media->stableKey,
                'eligible' => $eligible,
                'suitability' => $assessment['suitability'] ?? SemanticSuitabilityPolicy::UNKNOWN,
                'diagnostic' => $eligible ? null : ($assessment['diagnostic'] ?? 'MEDIA_CANDIDATE_INELIGIBLE'),
                'basis' => $assessment['basis'] ?? '',
                'relationship_class' => $assessment['relationship_class'] ?? 'UNKNOWN',
                'relation_path' => $assessment['relation_path'] ?? [],
                'semantic_tier' => $assessment['semantic_tier'] ?? 'UNKNOWN_SCOPE',
                'score_components' => $score,
                'media' => $media,
            ];
        }

        usort($candidates, function (array $left, array $right): int {
            return ((int) ($right['eligible'] ?? 0) <=> (int) ($left['eligible'] ?? 0))
                ?: ((int) (($right['score_components']['semantic_tier'] ?? 0)) <=> (int) (($left['score_components']['semantic_tier'] ?? 0)))
                ?: ((int) (($right['score_components']['preferred_view'] ?? 0)) <=> (int) (($left['score_components']['preferred_view'] ?? 0)))
                ?: ((int) (($right['score_components']['dimensions'] ?? 0)) <=> (int) (($left['score_components']['dimensions'] ?? 0)))
                ?: (($left['stable_key'] ?? '') <=> ($right['stable_key'] ?? ''));
        });

        $winner = null;
        foreach ($candidates as $candidate) {
            if (($candidate['eligible'] ?? false) !== true) continue;
            $winner = $candidate['media'] instanceof Media ? $candidate['media'] : null;
            break;
        }
        $diagnostics = [];
        if ($winner === null) $diagnostics[] = ['code' => 'NO_SEMANTICALLY_ELIGIBLE_MEDIA', 'slot' => $blueprint->slot, 'subject_ids' => $subjectIds];
        foreach ($candidates as &$candidate) unset($candidate['media']);
        unset($candidate);
        return ['media' => $winner, 'candidates' => $candidates, 'diagnostics' => $diagnostics];
    }

    /** @return list<string> */
    private function subjectIds(MediaSeoBlueprint $blueprint): array
    {
        $ids = [];
        foreach (['subject_ids', 'canonical_subject_ids'] as $key) foreach ((array) ($blueprint->subjectContext[$key] ?? []) as $id) {
            $id = trim((string) $id);
            if ($id !== '') $ids[$id] = true;
        }
        return array_keys($ids);
    }

    /** @return array<string,int|string> */
    private function score(Media $media, MediaSeoBlueprint $blueprint, array $assessment, bool $hasPublicAsset, bool $reused): array
    {
        $detailType = strtoupper(trim((string) ($media->provenance['detail_type'] ?? '')));
        $preferredView = $blueprint->preferredView !== null && $detailType === strtoupper($blueprint->preferredView) ? 20 : 0;
        $dimensions = 0;
        foreach ($this->assets->listByMediaId($media->canonicalId) as $asset) {
            if (($asset->width ?? 0) >= $blueprint->minimumWidth && ($asset->height ?? 0) >= $blueprint->minimumHeight) $dimensions = max($dimensions, 10);
        }
        return [
            'semantic_tier' => (int) (($assessment['score_components']['semantic_tier'] ?? 0)),
            'preferred_view' => $preferredView,
            'dimensions' => $dimensions,
            'public_asset' => $hasPublicAsset ? 1 : 0,
            'reuse_penalty' => $reused ? -1 : 0,
        ];
    }
}
