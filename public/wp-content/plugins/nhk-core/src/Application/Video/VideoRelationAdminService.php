<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Knowledge\KnowledgeService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository,KnowledgeRepository,SourceRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Governance\{CommandCanonicalizer,Proposal};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Domain\Knowledge\Evidence;
use NHK\Core\Infrastructure\Admin\VideoRelationAdminContract;
use NHK\Core\Shared\Uuid\UuidCodec;

final class VideoRelationAdminService
{
    public function __construct(private GovernanceService $governance, private ProposalRepository $proposals, private VideoRepository $videos, private AuthorityRepository $authority, private KnowledgeService $knowledge, private KnowledgeRepository $claims, private SourceRepository $sources, private EvidenceRepository $evidence) {}

    public function context(string $videoId): array
    {
        $video = $this->video($videoId); $proposal = $this->proposals->findLatestVideoIngest($video->canonicalId); $source = $this->provenance($video);
        return ['video' => ['id' => $video->canonicalId, 'title' => $video->title, 'platform' => $video->platform, 'external_id' => $video->externalVideoId, 'url' => $video->canonicalUrl, 'active' => $video->active, 'revision' => $video->revision], 'video_proposal' => $proposal ? ['id' => $proposal->id, 'state' => $proposal->state->value, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint, 'revision' => $proposal->revision] : null, 'provenance' => ['platform' => 'youtube', 'external_id' => $source['external_video_id'], 'locator' => $source['canonical_source_url'], 'visibility' => 'PRIVATE', 'origin' => 'VIDEO_CANONICAL_PROVENANCE'], 'target_types' => (new VideoRelationAdminContract())->targetTypes(), 'predicate' => 'about', 'evidence_origin' => 'EXPLICIT_USER_RELATION', 'diagnostic' => $proposal === null ? 'VIDEO_PROPOSAL_NOT_FOUND' : null];
    }

    public function create(string $videoId, string $targetType, string $targetId, string $actor = ''): array
    {
        $video = $this->video($videoId); $target = $this->authority->findByCanonicalId($targetId);
        if ($target === null || $target->entityType !== $targetType || !$target->active()) throw new \InvalidArgumentException('AUTHORITY_TARGET_NOT_FOUND');
        $videoProposal = $this->proposals->findLatestVideoIngest($video->canonicalId); if ($videoProposal === null) throw new \InvalidArgumentException('VIDEO_PROPOSAL_NOT_FOUND');
        $evidence = $this->resolveEvidence($video, $targetType, $targetId);
        $payload = (new VideoRelationAdminContract())->payload($video->canonicalId, $targetType, $targetId, [['evidence_id' => $evidence->canonicalId]], $videoProposal->bindingFingerprint());
        $content = hash('sha256', CommandCanonicalizer::canonicalize($payload)); $dependency = hash('sha256', CommandCanonicalizer::canonicalize([$evidence->canonicalId]));
        $idempotency = 'nhk:admin:video-relation:' . hash('sha256', CommandCanonicalizer::canonicalize([$video->canonicalId, $targetType, $targetId, $payload['predicate'], $payload['evidence_refs'], $videoProposal->bindingFingerprint()]));
        $proposal = $this->governance->create(new Proposal(UuidCodec::newV7(), 'relation', 'relation_create', $payload, $content, null, $dependency, actor: $actor !== '' ? $actor : (string) get_current_user_id(), idempotencyKey: $idempotency, entityType: 'relation'));
        return ['proposal_id' => $proposal->id, 'state' => $proposal->state->value, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint, 'evidence' => ['id' => $evidence->canonicalId, 'claim_id' => $evidence->claimId, 'source_id' => $evidence->sourceId, 'locator' => $evidence->locator, 'visibility' => $evidence->metadata['visibility'], 'fingerprint' => $evidence->metadata['reconciliation_fingerprint']], 'message' => 'Relation proposal đã tạo; tiếp tục Submit → Approve → Eligibility → Controlled Apply.'];
    }

    private function video(string $id): Video { if (!UuidCodec::isValid($id)) throw new \InvalidArgumentException('UUID không hợp lệ.'); return $this->videos->findByCanonicalId($id) ?? throw new \InvalidArgumentException('VIDEO_NOT_FOUND'); }
    private function provenance(Video $video): array { if ($video->platform !== 'youtube' || !$video->hasValidPublicReference()) throw new \RuntimeException('CANONICAL_VIDEO_PROVENANCE_REQUIRED'); $snapshot = is_array($video->metadata['source_snapshot'] ?? null) ? $video->metadata['source_snapshot'] : []; return ['platform' => 'youtube', 'external_video_id' => $video->externalVideoId, 'canonical_source_url' => $video->canonicalUrl, 'source_title' => (string) ($snapshot['title'] ?? $video->title), 'source_description' => (string) ($snapshot['description'] ?? '')]; }
    private function resolveEvidence(Video $video, string $targetType, string $targetId): Evidence
    {
        $sourceData = $this->provenance($video); $sourceKey = 'nhk:video-source:' . hash('sha256', CommandCanonicalizer::canonicalize(['youtube', $video->externalVideoId, $video->canonicalUrl])); $sourceMetadata = ['visibility' => 'PRIVATE', 'origin' => 'VIDEO_CANONICAL_PROVENANCE', 'video_uuid' => $video->canonicalId, 'platform' => 'youtube', 'external_video_id' => $video->externalVideoId];
        $source = $this->sources->findByStableKey($sourceKey); if ($source !== null) { if (!$source->active || $source->locator !== $video->canonicalUrl || $source->metadata !== $sourceMetadata) throw new \RuntimeException('WRONG_SOURCE_PROVENANCE'); } else { $this->knowledge->createSource($sourceKey, $sourceData['source_title'] ?: 'YouTube video source', 'website', $video->canonicalUrl, $sourceMetadata); $source = $this->sources->findByStableKey($sourceKey) ?? throw new \RuntimeException('CANONICAL_SOURCE_REQUIRED'); }
        $claimKey = 'nhk:video-relation-claim:' . hash('sha256', CommandCanonicalizer::canonicalize([$video->canonicalId, $targetType, $targetId, 'about'])); $claimProvenance = ['metadata' => ['origin' => 'VIDEO_RELATION_RECONCILIATION'], 'video_uuid' => $video->canonicalId, 'target_type' => $targetType, 'target_uuid' => $targetId, 'predicate' => 'about'];
        $claim = $this->claims->findByStableKey($claimKey); if ($claim !== null) { if (!$claim->active || $claim->provenance !== $claimProvenance) throw new \RuntimeException('WRONG_CLAIM_PROVENANCE'); } else { $this->knowledge->createClaim($claimKey, 'Video ' . $video->canonicalId . ' has a registered about relation to ' . $targetType . ' ' . $targetId . '.', 'provenance', $claimProvenance); $claim = $this->claims->findByStableKey($claimKey) ?? throw new \RuntimeException('CANONICAL_CLAIM_REQUIRED'); }
        $fingerprint = hash('sha256', CommandCanonicalizer::canonicalize([$claimKey, $source->stableKey, $video->canonicalId, $targetType, $targetId, 'about'])); $evidenceId = UuidCodec::v5('nhk:video-relation-evidence:' . $fingerprint); $metadata = ['visibility' => 'PRIVATE', 'origin' => 'VIDEO_CANONICAL_PROVENANCE', 'video_uuid' => $video->canonicalId, 'reconciliation_fingerprint' => $fingerprint];
        $existing = $this->evidence->findByCanonicalId($evidenceId); if ($existing !== null) { if (!$existing->active || $existing->claimId !== $claim->canonicalId || $existing->sourceId !== $source->canonicalId || $existing->metadata !== $metadata || $existing->locator !== $video->canonicalUrl) throw new \RuntimeException('WRONG_EVIDENCE_PROVENANCE'); return $existing; }
        $excerpt = $sourceData['source_description'] ?: ($sourceData['source_title'] ?: 'Canonical YouTube source: ' . $video->canonicalUrl); $this->knowledge->citeWithId($evidenceId, $claim->canonicalId, $source->canonicalId, $excerpt, 'supports', $video->canonicalUrl, $metadata);
        return $this->evidence->findByCanonicalId($evidenceId) ?? throw new \RuntimeException('CANONICAL_EVIDENCE_REQUIRED');
    }
}
