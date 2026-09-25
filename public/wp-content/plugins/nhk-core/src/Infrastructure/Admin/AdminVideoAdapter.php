<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Application\Video\VideoMediaPresentationResolver;
use NHK\Core\Domain\Video\Video;

final class AdminVideoAdapter
{
    /** @param iterable<Video> $videos */
    public function __construct(private iterable $videos, private ?VideoMediaPresentationResolver $presentation = null) {}

    /** @return list<array<string,mixed>> */
    public function find(string $query = ''): array
    {
        $query = strtolower(trim($query)); $rows = [];
        foreach ($this->videos as $video) {
            if (!$video instanceof Video) continue;
            $haystack = strtolower(implode(' ', [$video->title, $video->externalVideoId, $video->canonicalId, $video->platform]));
            if ($query !== '' && !str_contains($haystack, $query)) continue;
            $attachments = $this->attachments($video);
            $thumbnail = $this->presentation?->resolve($video) ?? ['status' => 'missing', 'status_label' => 'Thiếu ảnh đại diện', 'media_id' => null, 'usage_id' => null, 'usage_revision' => null, 'thumbnail_url' => null, 'thumbnail' => []];
            $metadata = is_array($video->metadata) ? $video->metadata : [];
            $slug = is_array($metadata['public_identity'] ?? null) ? trim((string) ($metadata['public_identity']['current_slug'] ?? '')) : '';
            $rows[] = ['id' => $video->canonicalId, 'canonical_short_id' => substr($video->canonicalId, 0, 8), 'title' => $video->title !== '' ? $video->title : 'Video chưa có tiêu đề', 'platform' => $video->platform, 'external_id' => $video->externalVideoId, 'active' => $video->active, 'publication_state' => $video->active ? 'active' : 'retired', 'frontend_state' => $this->frontendState($video), 'primary_semantic_target' => $this->primaryTarget($attachments), 'relation_count' => count($attachments), 'evidence_count' => $this->evidenceCount($attachments), 'revision' => $video->revision, 'thumbnail_media_id' => $video->thumbnailMediaId, 'representative_media_id' => $thumbnail['media_id'], 'representative_usage_id' => $thumbnail['usage_id'], 'representative_usage_revision' => $thumbnail['usage_revision'], 'thumbnail_url' => $thumbnail['thumbnail_url'], 'thumbnail_status' => $thumbnail['status'], 'thumbnail_status_label' => $thumbnail['status_label'], 'public_url' => $slug !== '' ? '/video/' . rawurlencode($slug) . '/' : null, 'url' => $video->canonicalUrl];
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public static function filterThumbnailStatus(array $rows, string $status): array
    {
        $status = strtolower(trim($status));
        if ($status === '' || $status === 'all') return $rows;
        return array_values(array_filter($rows, static fn (array $row): bool => ($row['thumbnail_status'] ?? '') === $status));
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

    private function frontendState(Video $video): string
    {
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        return is_array($metadata['public_identity'] ?? null) && trim((string) ($metadata['public_identity']['current_slug'] ?? '')) !== '' ? 'available' : 'missing';
    }
}
