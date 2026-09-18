<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Domain\Media\{Media, MediaAsset, VisualSupportRequirement, VisualSupportRequirementStateRegistry};
use NHK\Core\Domain\Media\MediaUsageRoleRegistry;

final class VisualSupportPublicProjection
{
    public function __construct(private ?MediaUsageRepository $usages = null, private ?\Closure $attachmentReader = null) {}

    /** @param list<MediaAsset> $assets @return array<string,mixed>|null */
    public function resolve(VisualSupportRequirement $requirement, ?Media $media, array $assets): ?array
    {
        if ($requirement->state !== VisualSupportRequirementStateRegistry::RESOLVED || $media === null || $requirement->mediaId !== $media->canonicalId || !$media->active || $media->readiness !== 'ready' || $media->isSystemPlaceholder()) return null;
        $asset = (new PublicMediaAssetSelector())->canonical(array_values(array_filter($assets, static fn (mixed $candidate): bool => $candidate instanceof MediaAsset && $candidate->mediaId === $media->canonicalId)));
        if ($asset === null) return null;
        $filename = is_string($asset->metadata['canonical_filename'] ?? null) ? $asset->metadata['canonical_filename'] : basename(str_replace('\\', '/', $asset->storageKey));
        if (trim($filename) === '') return null;
        return array_merge($this->metadata($requirement, $media, $asset), ['media_id' => $media->canonicalId, 'asset_id' => $asset->assetId, 'url' => (new PublicMediaAssetUrlResolver())->path($filename), 'visual_intent' => $requirement->visualIntent, 'feature_key' => $requirement->featureKey]);
    }

    /** @return array{title:string,alt:string,caption:string,metadata_source:string} */
    private function metadata(VisualSupportRequirement $requirement, Media $media, MediaAsset $asset): array
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
        $attachment = $this->attachmentMetadata($asset);
        foreach (['title', 'alt', 'caption'] as $field) {
            foreach ($candidates as $candidate) {
                $value = trim((string) ($field === 'alt' ? $candidate->altText : ($field === 'caption' ? $candidate->caption : $candidate->title)));
                if ($value === '') continue;
                $values[$field] = $value;
                $sources[] = $candidate->role === MediaUsageRoleRegistry::REPRESENTATIVE ? 'SUBJECT_REPRESENTATIVE' : 'MEDIA_USAGE';
                break;
            }
            if (!array_key_exists($field, $values) && trim($media->canonicalName) !== '' && $field !== 'caption') {
                $neutral = trim($media->canonicalName);
                $values[$field] = $neutral;
                $sources[] = 'MEDIA_NEUTRAL';
            }
            if (!array_key_exists($field, $values)) {
                $attachmentValue = trim((string) ($attachment[$field] ?? ''));
                if ($attachmentValue !== '') {
                    $values[$field] = $attachmentValue;
                    $sources[] = 'WORDPRESS_ATTACHMENT';
                }
            }
            $values[$field] ??= '';
        }
        $rank = ['SUBJECT_REPRESENTATIVE' => 0, 'MEDIA_USAGE' => 1, 'MEDIA_NEUTRAL' => 2, 'WORDPRESS_ATTACHMENT' => 3, 'MISSING' => 4];
        usort($sources, static fn (string $left, string $right): int => ($rank[$left] ?? 99) <=> ($rank[$right] ?? 99));
        $metadataSource = $sources[0] ?? 'MISSING';
        return array_merge($values, ['metadata_source' => $metadataSource]);
    }

    /** @return array<string,mixed> */
    private function attachmentMetadata(MediaAsset $asset): array
    {
        $attachmentId = (int) ($asset->metadata['wordpress_attachment_id'] ?? 0);
        if ($attachmentId < 1 || $this->attachmentReader === null) return [];
        $metadata = ($this->attachmentReader)($attachmentId);
        return is_array($metadata) && (int) ($metadata['attachment_id'] ?? 0) === $attachmentId ? $metadata : [];
    }
}
