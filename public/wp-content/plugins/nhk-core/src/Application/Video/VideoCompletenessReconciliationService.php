<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Domain\Video\Video;

/**
 * Rebuilds and persists the current Video completeness from canonical owners.
 * Graph and Evidence are read-only inputs; Video metadata remains owned by the
 * Video service/repository and is changed only inside the governed caller.
 */
final class VideoCompletenessReconciliationService
{
    private VideoService $videosService;

    public function __construct(
        private VideoRepository $videos,
        private GraphService $graph,
        private CanonicalDependencyValidator $dependencies,
        private ?VideoCompletenessPolicy $policy = null,
    ) {
        $this->videosService = new VideoService($videos);
    }

    public function reconcile(string $videoId): Video
    {
        $video = $this->videos->findByCanonicalId($videoId);
        if (!$video instanceof Video) throw new \RuntimeException('VIDEO_CANONICAL_READBACK_UNAVAILABLE');

        $attachments = $this->canonicalAttachments($video);
        $metadata = is_array($video->metadata) ? $video->metadata : [];
        $result = ($this->policy ?? new VideoCompletenessPolicy())->evaluateAfterCanonicalReadBack($metadata, $attachments);
        $metadata['semantic_attachments'] = array_values($attachments);
        $metadata['completeness'] = [
            'publishable' => $result->publishable,
            'blockers' => $result->blockers,
            'warnings' => $result->warnings,
        ];
        if ($metadata === $video->metadata) return $video;

        return $this->videosService->update(
            $video->canonicalId,
            $video->title,
            $metadata,
            $video->thumbnailMediaId,
            $video->revision,
        );
    }

    /** @return list<array<string,mixed>> */
    private function canonicalAttachments(Video $video): array
    {
        $stored = is_array($video->metadata['semantic_attachments'] ?? null) ? $video->metadata['semantic_attachments'] : [];
        $page = $this->graph->findOutgoing(new NodeReference('video', $video->canonicalId), 'about', 0, 200, false);
        $attachments = [];
        foreach ((array) ($page['items'] ?? []) as $edge) {
            if (!$edge instanceof \NHK\Core\Domain\Graph\GraphEdge || !$edge->isActive()) continue;
            $targetType = $edge->target->reference->endpoint_type;
            $targetUuid = $edge->target->reference->endpoint_key;
            foreach ($stored as $attachment) {
                if (!$this->matchesTarget($attachment, $targetType, $targetUuid)) continue;
                if (!$this->hasValidEvidence($attachment)) continue;
                $attachment['predicate'] = 'about';
                $attachment['target_type'] = $targetType;
                $attachment['target_uuid'] = $targetUuid;
                $attachments[] = $attachment;
                break;
            }
        }
        return $attachments;
    }

    private function matchesTarget(mixed $attachment, string $targetType, string $targetUuid): bool
    {
        return is_array($attachment)
            && strtolower(trim((string) ($attachment['predicate'] ?? 'about'))) === 'about'
            && strtolower(trim((string) ($attachment['target_type'] ?? ''))) === strtolower($targetType)
            && strtolower(trim((string) ($attachment['target_uuid'] ?? $attachment['target_id'] ?? ''))) === strtolower($targetUuid);
    }

    private function hasValidEvidence(mixed $attachment): bool
    {
        $refs = is_array($attachment['evidence_refs'] ?? null) ? $attachment['evidence_refs'] : [];
        if ($refs === []) return false;
        foreach ($refs as $reference) {
            if (!is_array($reference)) return false;
            $evidenceId = trim((string) ($reference['evidence_id'] ?? ''));
            if ($evidenceId === '') return false;
            try {
                $this->dependencies->evidence($evidenceId);
            } catch (\Throwable) {
                return false;
            }
        }
        return true;
    }
}
