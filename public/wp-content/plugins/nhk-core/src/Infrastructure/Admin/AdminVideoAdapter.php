<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Domain\Media\{MediaUsage, MediaUsageRoleRegistry};
use NHK\Core\Domain\Video\Video;

final class AdminVideoAdapter
{
    /** @var list<MediaUsage> */
    private array $usages;

    /** @param iterable<Video> $videos @param iterable<MediaUsage> $usages */
    public function __construct(private iterable $videos, iterable $usages = [])
    {
        $this->usages = array_values(is_array($usages) ? $usages : iterator_to_array($usages, false));
    }

    /** @return list<array<string,mixed>> */
    public function find(string $query = ''): array
    {
        $query = strtolower(trim($query)); $rows = [];
        foreach ($this->videos as $video) {
            if (!$video instanceof Video) continue;
            $haystack = strtolower(implode(' ', [$video->title, $video->externalVideoId, $video->canonicalId, $video->platform]));
            if ($query !== '' && !str_contains($haystack, $query)) continue;
            $attachments = $this->attachments($video);
            $rows[] = ['id' => $video->canonicalId, 'canonical_short_id' => substr($video->canonicalId, 0, 8), 'title' => $video->title !== '' ? $video->title : 'Video chưa có tiêu đề', 'platform' => $video->platform, 'external_id' => $video->externalVideoId, 'active' => $video->active, 'publication_state' => $video->active ? 'active' : 'retired', 'frontend_state' => $this->frontendState($video), 'primary_semantic_target' => $this->primaryTarget($attachments), 'relation_count' => count($attachments), 'evidence_count' => $this->evidenceCount($attachments), 'revision' => $video->revision, 'thumbnail_media_id' => ($representative = $this->representative($video->canonicalId))?->mediaId, 'thumbnail_source' => $representative instanceof MediaUsage ? 'media_usage' : 'source_fallback', 'legacy_thumbnail_media_id' => $video->thumbnailMediaId, 'url' => $video->canonicalUrl];
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $relations @param list<array<string,mixed>> $evidence */
    public function detail(Video $video, array $relations, array $evidence, array $governance, array $frontendProjection): array
    {
        $attachments = $this->attachments($video);
        return [
            'video' => $this->find($video->externalVideoId)[0] ?? [],
            'metadata' => $video->metadata,
            'relations' => array_values($relations),
            'evidence' => array_values($evidence),
            'primary_semantic_target' => $this->primaryTarget($attachments),
            'relation_count' => count($relations),
            'evidence_count' => count($evidence),
            'governance' => $governance,
            'frontend_projection' => $frontendProjection,
            'frontend_state' => !empty($frontendProjection['eligible']) ? 'available' : 'missing',
            'representative_usage' => ($representative = $this->representative($video->canonicalId)) instanceof MediaUsage ? [
                'usage_id' => $representative->usageId,
                'media_id' => $representative->mediaId,
                'role' => $representative->role,
                'selection_source' => $representative->selectionSource,
                'selection_policy' => $representative->selectionPolicy,
                'revision' => $representative->revision,
            ] : null,
            'technical' => ['canonical_uuid' => $video->canonicalId, 'revision' => $video->revision],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function attachments(Video $video): array
    {
        $attachments = $video->metadata['semantic_attachments'] ?? [];
        return is_array($attachments) ? array_values(array_filter($attachments, 'is_array')) : [];
    }

    /** @param list<array<string,mixed>> $attachments */
    private function primaryTarget(array $attachments): ?array
    {
        $target = $attachments[0] ?? null;
        if (!is_array($target)) return null;
        $key = trim((string) ($target['target_key'] ?? $target['target_id'] ?? ''));
        $type = trim((string) ($target['target_type'] ?? ''));
        return $key === '' ? null : ['type' => $type, 'key' => $key];
    }

    /** @param list<array<string,mixed>> $attachments */
    private function evidenceCount(array $attachments): int
    {
        $count = 0;
        foreach ($attachments as $attachment) if (is_array($attachment['evidence_refs'] ?? null)) $count += count($attachment['evidence_refs']);
        return $count;
    }

    private function representative(string $videoId): ?MediaUsage
    {
        foreach ($this->usages as $usage) {
            if (!$usage instanceof MediaUsage) continue;
            if ($usage->endpointType !== 'video' || $usage->endpointKey !== $videoId) continue;
            if ($usage->role !== MediaUsageRoleRegistry::REPRESENTATIVE || $usage->activeSlot === 'retired') continue;
            return $usage;
        }
        return null;
    }

    private function frontendState(Video $video): string
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        return is_array($metadata['public_identity'] ?? null) && trim((string) ($metadata['public_identity']['current_slug'] ?? '')) !== '' ? 'available' : 'missing';
    }
}
