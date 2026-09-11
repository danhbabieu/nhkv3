<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\{Media, MediaAsset};

final class VisualSupportMediaSuitability
{
    /** @param list<MediaAsset> $assets @param array<string,mixed> $context @return array{matched:bool,score:int,reason:string} */
    public function evaluate(Media $media, array $assets, array $context): array
    {
        if (!$media->active) return ['matched' => false, 'score' => 0, 'reason' => 'MEDIA_INACTIVE'];
        if ($media->isSystemPlaceholder()) return ['matched' => false, 'score' => 0, 'reason' => 'MEDIA_PLACEHOLDER'];
        if ($assets === [] || !array_filter($assets, static fn (mixed $asset): bool => $asset instanceof MediaAsset && $asset->mediaId === $media->canonicalId)) return ['matched' => false, 'score' => 0, 'reason' => 'MEDIA_ASSET_MISSING'];
        $declared = $media->provenance['visual_support_contexts'] ?? [];
        if (!is_array($declared) || !array_is_list($declared)) return ['matched' => false, 'score' => 0, 'reason' => 'VISUAL_CONTEXT_MISSING'];
        foreach ($declared as $candidate) {
            if (!is_array($candidate) || !$this->sameContext($candidate, $context)) continue;
            $score = 100;
            if ($media->readiness === 'ready') $score += 20;
            foreach ($assets as $asset) if ($asset instanceof MediaAsset && str_starts_with(strtolower($asset->mimeType), 'image/')) $score += 5;
            return ['matched' => true, 'score' => $score, 'reason' => 'EXACT_CONTEXT_MATCH'];
        }
        return ['matched' => false, 'score' => 0, 'reason' => 'VISUAL_CONTEXT_MISMATCH'];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameContext(array $left, array $right): bool
    {
        foreach (['subject_type', 'subject_id', 'scope', 'facet', 'feature_key', 'visual_intent'] as $key) if ((string) ($left[$key] ?? '') !== (string) ($right[$key] ?? '')) return false;
        return true;
    }
}
