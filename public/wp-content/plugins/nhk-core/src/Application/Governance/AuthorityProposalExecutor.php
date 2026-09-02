<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Media\{MediaIngestGateway, MediaService};
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Domain\Graph\GraphEdge;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};

final class AuthorityProposalExecutor
{
    public function __construct(private AuthorityService $authority, private ?GraphService $graph = null, private ?MediaService $media = null, private ?VideoService $video = null, private ?KnowledgeService $knowledge = null, private ?MediaIngestGateway $mediaGateway = null) {}

    public function __invoke(Proposal $proposal): AuthorityEntity|GraphEdge|Media|Video|KnowledgeClaim|Source|Evidence
    {
        if ($proposal->entityType === 'media' && $proposal->operation === 'ingest') {
            if (!$this->media) throw new \RuntimeException('Media executor is not configured.');
            $payload = $proposal->payload;
            $packet = [
                'stable_key' => (string) ($payload['stable_key'] ?? ''),
                'name' => (string) ($payload['name'] ?? ''),
                'readiness' => (string) ($payload['readiness'] ?? 'draft'),
                'provenance' => is_array($payload['provenance'] ?? null) ? $payload['provenance'] : [],
                'assets' => is_array($payload['assets'] ?? null) ? $payload['assets'] : [],
                'usages' => is_array($payload['usages'] ?? null) ? $payload['usages'] : [],
            ];
            return $this->mediaGateway?->ingest($packet) ?? $this->media->ingest(
                (string) ($payload['stable_key'] ?? ''),
                (string) ($payload['name'] ?? ''),
                (string) ($payload['readiness'] ?? 'draft'),
                is_array($payload['provenance'] ?? null) ? $payload['provenance'] : [],
                is_array($payload['assets'] ?? null) ? $payload['assets'] : [],
                is_array($payload['usages'] ?? null) ? $payload['usages'] : [],
            );
        }
        if ($proposal->entityType === 'video' && $proposal->operation === 'ingest') {
            if (!$this->video) throw new \RuntimeException('Video executor is not configured.');
            $payload = $proposal->payload;
            $video = $this->video->ingestUrl(
                (string) ($payload['url'] ?? ''),
                (string) ($payload['title'] ?? ''),
                is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                isset($payload['thumbnail_media_id']) && (string) $payload['thumbnail_media_id'] !== '' ? (string) $payload['thumbnail_media_id'] : null,
                isset($payload['canonical_id']) && (string) $payload['canonical_id'] !== '' ? (string) $payload['canonical_id'] : null,
            );
            $this->applyVideoAttachments($proposal, $video);
            return $video;
        }
        if ($proposal->entityType === 'video' && in_array($proposal->operation, ['update', 'retire', 'reactivate'], true)) {
            if (!$this->video) throw new \RuntimeException('Video executor is not configured.');
            $payload = $proposal->payload;
            $target = $proposal->targetUuid ?: $proposal->subjectId;
            $video = match ($proposal->operation) {
                'update' => $this->video->update($target, (string) ($payload['title'] ?? ''), is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [], isset($payload['thumbnail_media_id']) && (string) $payload['thumbnail_media_id'] !== '' ? (string) $payload['thumbnail_media_id'] : null, $proposal->expectedRevision),
                'retire' => $this->video->retire($target, $proposal->expectedRevision),
                'reactivate' => $this->video->reactivate($target, $proposal->expectedRevision),
            };
            if ($proposal->operation === 'update') $this->applyVideoAttachments($proposal, $video);
            return $video;
        }
        if (in_array($proposal->entityType, ['knowledge', 'source', 'evidence'], true)) return $this->knowledge($proposal);
        if (in_array($proposal->operation, ['relation_create', 'relation_retire', 'relation_reactivate'], true)) {
            return $this->relation($proposal);
        }
        $payload = $proposal->payload;
        $target = $proposal->targetUuid ?: $proposal->subjectId;
        return match ($proposal->operation) {
            'create', 'ingest' => $this->authority->create(
                $proposal->entityType,
                (string) ($payload['stable_key'] ?? ''),
                (string) ($payload['name'] ?? ''),
                is_array($payload['entity_payload'] ?? null) ? $payload['entity_payload'] : [],
            ),
            'rename' => $this->authority->rename($target, (string) ($payload['name'] ?? ''), $proposal->expectedRevision),
            'update' => $this->authority->update($target, is_array($payload['entity_payload'] ?? null) ? $payload['entity_payload'] : [], $proposal->expectedRevision),
            'retire' => $this->authority->retire($target, $proposal->expectedRevision),
            'reactivate' => $this->authority->reactivate($target, $proposal->expectedRevision),
            default => throw new \InvalidArgumentException('Unsupported authority proposal operation: ' . $proposal->operation),
        };
    }

    private function knowledge(Proposal $proposal): KnowledgeClaim|Source|Evidence
    {
        if (!$this->knowledge) throw new \RuntimeException('Knowledge executor is not configured.');
        $payload = $proposal->payload;
        if (is_array($payload['entity_payload'] ?? null)) $payload = array_merge($payload, $payload['entity_payload']);
        $target = $proposal->targetUuid ?: $proposal->subjectId;
        if (in_array($proposal->operation, ['create', 'ingest'], true)) return match ($proposal->entityType) {
            'knowledge' => $this->knowledge->createClaim((string) ($payload['stable_key'] ?? ''), (string) ($payload['text'] ?? $payload['claim_text'] ?? ''), (string) ($payload['claim_type'] ?? $payload['type'] ?? 'fact'), is_array($payload['provenance'] ?? null) ? $payload['provenance'] : []),
            'source' => $this->knowledge->createSource((string) ($payload['stable_key'] ?? ''), (string) ($payload['title'] ?? ''), (string) ($payload['source_type'] ?? $payload['type'] ?? 'website'), isset($payload['locator']) ? (string) $payload['locator'] : null, is_array($payload['metadata'] ?? null) ? $payload['metadata'] : []),
            'evidence' => $this->knowledge->cite((string) ($payload['claim_id'] ?? ''), (string) ($payload['source_id'] ?? ''), (string) ($payload['excerpt'] ?? ''), (string) ($payload['relation'] ?? 'supports'), isset($payload['locator']) ? (string) $payload['locator'] : null, is_array($payload['metadata'] ?? null) ? $payload['metadata'] : []),
        };
        return match ($proposal->entityType) {
            'knowledge' => match ($proposal->operation) {
                'update' => $this->knowledge->updateClaim($target, (string) ($payload['text'] ?? $payload['claim_text'] ?? ''), (string) ($payload['claim_type'] ?? $payload['type'] ?? 'fact'), is_array($payload['provenance'] ?? null) ? $payload['provenance'] : [], $proposal->expectedRevision),
                'retire' => $this->knowledge->retireClaim($target, $proposal->expectedRevision),
                'reactivate' => $this->knowledge->reactivateClaim($target, $proposal->expectedRevision),
                default => throw new \InvalidArgumentException('Unsupported knowledge proposal operation: ' . $proposal->operation),
            },
            'source' => match ($proposal->operation) {
                'update' => $this->knowledge->updateSource($target, (string) ($payload['title'] ?? ''), (string) ($payload['source_type'] ?? $payload['type'] ?? 'website'), isset($payload['locator']) ? (string) $payload['locator'] : null, is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [], $proposal->expectedRevision),
                'retire' => $this->knowledge->retireSource($target, $proposal->expectedRevision),
                'reactivate' => $this->knowledge->reactivateSource($target, $proposal->expectedRevision),
                default => throw new \InvalidArgumentException('Unsupported source proposal operation: ' . $proposal->operation),
            },
            'evidence' => match ($proposal->operation) {
                'update' => $this->knowledge->updateEvidence($target, (string) ($payload['relation'] ?? 'supports'), (string) ($payload['excerpt'] ?? ''), isset($payload['locator']) ? (string) $payload['locator'] : null, is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [], $proposal->expectedRevision),
                'retire' => $this->knowledge->retireEvidence($target, $proposal->expectedRevision),
                'reactivate' => $this->knowledge->reactivateEvidence($target, $proposal->expectedRevision),
                default => throw new \InvalidArgumentException('Unsupported evidence proposal operation: ' . $proposal->operation),
            },
            default => throw new \InvalidArgumentException('Unsupported knowledge proposal entity type: ' . $proposal->entityType),
        };
    }

    private function relation(Proposal $proposal): mixed
    {
        if (!$this->graph) throw new \RuntimeException('Graph executor is not configured.');
        if ($proposal->operation === 'relation_create') {
            return $this->graph->create(
                new NodeReference((string) ($proposal->payload['source_type'] ?? ''), (string) ($proposal->payload['source_key'] ?? '')),
                (string) ($proposal->payload['predicate'] ?? ''),
                new NodeReference((string) ($proposal->payload['target_type'] ?? ''), (string) ($proposal->payload['target_key'] ?? '')),
            );
        }
        $edgeId = $proposal->targetUuid ?: $proposal->subjectId;
        return $proposal->operation === 'relation_retire'
            ? $this->graph->retire($edgeId, $proposal->expectedRevision)
            : $this->graph->reactivate($edgeId, $proposal->expectedRevision);
    }

    private function applyVideoAttachments(Proposal $proposal, Video $video): void
    {
        $metadata = is_array($proposal->payload['metadata'] ?? null) ? $proposal->payload['metadata'] : [];
        if (!array_key_exists('intake_version', $metadata) && $proposal->operation !== 'ingest') return;
        $completeness = is_array($metadata['completeness'] ?? null) ? $metadata['completeness'] : [];
        $blockers = array_values(array_filter((array) ($completeness['blockers'] ?? []), static fn (mixed $blocker): bool => is_string($blocker) && trim($blocker) !== ''));
        if ($blockers !== []) throw new \RuntimeException('VIDEO_COMPLETENESS_BLOCKED:' . implode(',', $blockers));
        $attachments = is_array($metadata['semantic_attachments'] ?? null) ? $metadata['semantic_attachments'] : [];
        if ($attachments === []) throw new \RuntimeException('NO_SEMANTIC_ATTACHMENT');
        if ($this->graph === null) throw new \RuntimeException('Graph executor is not configured.');
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) throw new \RuntimeException('PROPOSAL_VALIDATION_FAILED');
            $this->graph->create(
                new NodeReference('video', $video->canonicalId),
                (string) ($attachment['predicate'] ?? ''),
                new NodeReference((string) ($attachment['target_type'] ?? ''), (string) ($attachment['target_key'] ?? '')),
            );
        }
    }
}
