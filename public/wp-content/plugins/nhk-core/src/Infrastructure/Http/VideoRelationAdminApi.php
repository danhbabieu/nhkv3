<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Contracts\Knowledge\EvidenceRepository;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal};
use NHK\Core\Infrastructure\Admin\VideoRelationAdminContract;
use NHK\Core\Shared\Uuid\UuidCodec;

final class VideoRelationAdminApi
{
    public function __construct(private GovernanceService $governance, private ProposalRepository $proposals, private VideoRepository $videos, private AuthorityRepository $authority, private EntityTypeRegistry $types, private EvidenceRepository $evidence) {}

    public function register(): void
    {
        register_rest_route('nhk/v1', '/admin/video-relation/context/(?P<video>[0-9A-Fa-f-]{36})', ['methods' => 'GET', 'permission_callback' => fn (): bool => current_user_can('nhk_view_governance'), 'callback' => fn (\WP_REST_Request $request) => $this->context($request)]);
        register_rest_route('nhk/v1', '/admin/video-relation', ['methods' => 'POST', 'permission_callback' => fn (): bool => current_user_can('nhk_create_proposals'), 'callback' => fn (\WP_REST_Request $request) => $this->create($request)]);
    }

    private function context(\WP_REST_Request $request): array|\WP_Error
    {
        try {
            $videoId = $this->uuid((string) $request['video']);
            $video = $this->videos->findByCanonicalId($videoId);
            if ($video === null) return new \WP_Error('nhk_video_not_found', 'Không tìm thấy Video canonical.', ['status' => 404]);
            $proposalId = trim((string) ($request->get_param('proposal_id') ?? ''));
            $proposal = $proposalId !== '' ? $this->proposals->find($this->uuid($proposalId)) : null;
            $proposalData = null;
            if ($proposal !== null) {
                if ($proposal->entityType !== 'video' || $proposal->operation !== 'ingest' || (string) ($proposal->payload['canonical_id'] ?? '') !== $videoId) return new \WP_Error('nhk_video_proposal_mismatch', 'Proposal không thuộc Video ingest này.', ['status' => 422]);
                $proposalData = ['id' => $proposal->id, 'state' => $proposal->state->value, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint, 'revision' => $proposal->revision];
            }
            return ['video' => ['id' => $video->canonicalId, 'title' => $video->title, 'platform' => $video->platform, 'external_id' => $video->externalVideoId, 'url' => $video->canonicalUrl, 'active' => $video->active, 'revision' => $video->revision], 'video_proposal' => $proposalData, 'target_types' => (new VideoRelationAdminContract())->targetTypes(), 'predicate' => 'about', 'evidence_origin' => 'EXPLICIT_USER_RELATION', 'diagnostic' => $proposalData === null ? 'VIDEO_PROPOSAL_REQUIRED' : null];
        } catch (\Throwable $error) { return $this->error($error); }
    }

    private function create(\WP_REST_Request $request): array|\WP_Error
    {
        try {
            $body = $request->get_json_params(); $body = is_array($body) ? $body : [];
            $videoId = $this->uuid((string) ($body['video_id'] ?? '')); $targetId = $this->uuid((string) ($body['target_id'] ?? ''));
            $targetType = sanitize_key((string) ($body['target_type'] ?? '')); $videoProposalId = $this->uuid((string) ($body['video_proposal_id'] ?? ''));
            $video = $this->videos->findByCanonicalId($videoId); $target = $this->authority->findByCanonicalId($targetId); $videoProposal = $this->proposals->find($videoProposalId);
            if ($video === null) throw new \InvalidArgumentException('VIDEO_NOT_FOUND');
            if ($target === null || $target->entityType !== $targetType || !$target->active()) throw new \InvalidArgumentException('AUTHORITY_TARGET_NOT_FOUND');
            if ($videoProposal === null || $videoProposal->entityType !== 'video' || $videoProposal->operation !== 'ingest' || (string) ($videoProposal->payload['canonical_id'] ?? '') !== $videoId) throw new \InvalidArgumentException('VIDEO_PROPOSAL_REQUIRED');
            $refs = is_array($body['evidence_refs'] ?? null) ? array_values($body['evidence_refs']) : [];
            $payload = (new VideoRelationAdminContract())->payload($videoId, $targetType, $targetId, $refs, $videoProposal->bindingFingerprint());
            foreach ($refs as $ref) {
                $item = $this->evidence->findByCanonicalId((string) $ref['evidence_id']);
                if ($item === null || !$item->active) throw new \InvalidArgumentException('CANONICAL_EVIDENCE_REQUIRED');
            }
            $content = hash('sha256', CommandCanonicalizer::canonicalize($payload)); $dependencyIds = array_map(static fn (array $ref): string => (string) $ref['evidence_id'], $refs);
            $dependency = hash('sha256', CommandCanonicalizer::canonicalize($dependencyIds)); $idempotency = 'nhk:admin:video-relation:' . hash('sha256', CommandCanonicalizer::canonicalize([$videoId, $targetType, $targetId, $payload['predicate'], $refs, $videoProposal->bindingFingerprint()]));
            $proposal = new Proposal(UuidCodec::newV7(), 'relation', 'relation_create', $payload, $content, null, $dependency, actor: (string) get_current_user_id(), idempotencyKey: $idempotency, entityType: 'relation');
            $created = $this->governance->create($proposal);
            return ['proposal_id' => $created->id, 'state' => $created->state->value, 'content_fingerprint' => $created->contentFingerprint, 'dependency_fingerprint' => $created->dependencyFingerprint, 'message' => 'Relation proposal đã tạo; tiếp tục Submit → Approve → Eligibility → Controlled Apply.'];
        } catch (\Throwable $error) { return $this->error($error); }
    }

    private function uuid(string $value): string
    {
        if (!UuidCodec::isValid($value)) throw new \InvalidArgumentException('UUID không hợp lệ.');
        return UuidCodec::fromBinary(UuidCodec::toBinary($value));
    }

    private function error(\Throwable $error): \WP_Error { $status = $error instanceof \InvalidArgumentException ? 400 : 500; return new \WP_Error('nhk_video_relation_error', $error->getMessage(), ['status' => $status]); }
}
