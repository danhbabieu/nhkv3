<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Domain\Media\{Media, MediaAsset, VisualSupportRequirement, VisualSupportRequirementStateRegistry};
use NHK\Core\Domain\Media\MediaUsageRoleRegistry;

final class VisualSupportPublicProjection
{
    public function __construct(private ?MediaUsageRepository $usages = null) {}

    /** @param list<MediaAsset> $assets @return array<string,mixed>|null */
    public function resolve(VisualSupportRequirement $requirement, ?Media $media, array $assets): ?array
    {
        if ($requirement->state !== VisualSupportRequirementStateRegistry::RESOLVED || $media === null || $requirement->mediaId !== $media->canonicalId || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return null;
        $asset = (new PublicMediaAssetSelector())->canonical(array_values(array_filter($assets, static fn (mixed $candidate): bool => $candidate instanceof MediaAsset && $candidate->mediaId === $media->canonicalId)));
        if ($asset === null) return null;
        $filename = is_string($asset->metadata['canonical_filename'] ?? null) ? $asset->metadata['canonical_filename'] : basename(str_replace('\\', '/', $asset->storageKey));
        if (trim($filename) === '') return null;
        return array_merge($this->metadata($requirement, $media), ['media_id' => $media->canonicalId, 'asset_id' => $asset->assetId, 'url' => (new PublicMediaAssetUrlResolver())->path($filename), 'visual_intent' => $requirement->visualIntent, 'feature_key' => $requirement->featureKey]);
    }

    /** @return array{title:string,alt:string,caption:string,metadata_source:string} */
    private function metadata(VisualSupportRequirement $requirement, Media $media): array
    {
        $usage = null;
        if ($this->usages !== null) {
            $candidates = array_values(array_filter($this->usages->listByMediaId($media->canonicalId), static fn (mixed $candidate): bool => $candidate instanceof \NHK\Core\Domain\Media\MediaUsage
                && $candidate->endpointType === $requirement->subjectType
                && $candidate->endpointKey === $requirement->subjectId
                && in_array($candidate->role, [MediaUsageRoleRegistry::REPRESENTATIVE, MediaUsageRoleRegistry::TECHNICAL_DETAIL, MediaUsageRoleRegistry::EVIDENCE], true)));
            usort($candidates, static fn (\NHK\Core\Domain\Media\MediaUsage $left, \NHK\Core\Domain\Media\MediaUsage $right): int => [$left->role === MediaUsageRoleRegistry::REPRESENTATIVE ? 0 : 1, $left->sortOrder, $left->usageId] <=> [$right->role === MediaUsageRoleRegistry::REPRESENTATIVE ? 0 : 1, $right->sortOrder, $right->usageId]);
            $usage = $candidates[0] ?? null;
        }
        $values = [];
        $sources = [];
        foreach (['title', 'alt', 'caption'] as $field) {
            $value = trim((string) ($field === 'alt' ? ($usage?->altText ?? '') : ($field === 'caption' ? ($usage?->caption ?? '') : ($usage?->title ?? ''))));
            if ($value !== '') {
                $values[$field] = $value;
                $sources[] = 'SUBJECT_REPRESENTATIVE';
                continue;
            }
            $neutral = trim($media->canonicalName);
            if ($neutral !== '' && $field !== 'caption') {
                $values[$field] = $neutral;
                $sources[] = 'MEDIA_NEUTRAL';
                continue;
            }
            $values[$field] = '';
        }
        $metadataSource = in_array('SUBJECT_REPRESENTATIVE', $sources, true)
            ? 'SUBJECT_REPRESENTATIVE'
            : (in_array('MEDIA_NEUTRAL', $sources, true) ? 'MEDIA_NEUTRAL' : 'MISSING');
        return array_merge($values, ['metadata_source' => $metadataSource]);
    }
}
