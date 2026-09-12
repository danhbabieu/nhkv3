<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Capture\CaptureVideoProvenancePlanner;
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Governance\ProposalEligibilityService;
use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Application\Video\VideoProposalReconciliationService;
use NHK\Core\Contracts\Governance\{DependencyRepository, EligibilityReader, GovernedLifecycle, ProposalRepository};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Governance\{DependencyGraph, Proposal, ProposalState};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\{InMemoryDependencyRepository, InMemoryProposalRepository};
use PHPUnit\Framework\TestCase;

final class VideoProposalReconciliationServiceTest extends TestCase
{
    private const OLD = '018f2f1e-7b2c-7abc-8def-0123456789ab';
    private const VIDEO = '018f2f1e-7b2c-7abc-8def-0123456789ac';
    private const VARIANT = '852da54d-457a-4397-a16d-52d9452ba766';

    public function test_existing_approved_video_proposal_is_rebuilt_with_canonical_provenance_and_old_approval_is_not_reused(): void
    {
        $repository = new InMemoryProposalRepository();
        $original = new Proposal(self::OLD, 'video', 'ingest', [
            'canonical_id' => self::VIDEO,
            'url' => 'https://www.youtube.com/watch?v=3x9naQn1H_4',
            'metadata' => [
                'source' => ['platform' => 'youtube', 'external_video_id' => '3x9naQn1H_4', 'canonical_source_url' => 'https://www.youtube.com/watch?v=3x9naQn1H_4', 'source_title' => 'Số 67 – Ô-đô 36/8 Nguyên Bản – Đời Máy Ba Vách Bệt Đáng Sưu Tầm'],
                'subject_resolution_packet' => ['id' => '018f2f1e-7b2c-7abc-8def-0123456789b0', 'type' => 'brand', 'name' => 'Odo'],
                'semantic_attachments' => [['target_type' => 'variant', 'target_uuid' => self::VARIANT, 'predicate' => 'about', 'evidence_refs' => [['kind' => 'USER_HINT', 'value' => 'legacy']]]],
            ],
        ], 'old-content', null, 'old-dependency', ProposalState::APPROVED, idempotencyKey: 'old-video', entityType: 'video');
        $repository->create($original);
        $lifecycle = new RecordingVideoReconciliationLifecycle($repository);
        $ids = ['source' => '018f2f1e-7b2c-7abc-8def-0123456789ad', 'knowledge' => '018f2f1e-7b2c-7abc-8def-0123456789ae', 'evidence' => '018f2f1e-7b2c-7abc-8def-0123456789af', 'video' => self::VIDEO];
        $appliedTypes = [];
        $apply = static function (string $id) use ($repository, $ids, &$appliedTypes): array {
            $proposal = $repository->find($id);
            $entityType = $proposal?->entityType ?? 'video';
            $appliedTypes[] = $entityType;
            $canonicalId = $ids[$entityType] ?? self::VIDEO;
            return ['canonical_id' => $canonicalId, 'canonical_readback' => ['canonical_id' => $canonicalId, 'active' => true, 'revision' => 1], 'idempotent' => false];
        };
        $publicReadbacks = [];
        $subjects = new SubjectResolutionService(static fn (string $hint): array => str_contains($hint, '36/8') ? [[
            'id' => self::VARIANT, 'type' => 'variant', 'name' => 'Odo 36/8', 'match' => 'exact_variant_reference',
        ]] : []);
        $service = new VideoProposalReconciliationService(
            $repository,
            $lifecycle,
            new GovernanceService($repository),
            new ProposalEligibilityService($repository, new DependencyGraph(new InMemoryDependencyRepository()), new AlwaysReadyEligibilityReader()),
            $apply,
            new EmptyVideoRepository(),
            new EmptySourceRepository(),
            new EmptyKnowledgeRepository(),
            new EmptyEvidenceRepository(),
            new CaptureVideoProvenancePlanner(),
            $subjects,
            publicIdentityReadback: static function (string $id) use (&$publicReadbacks): array {
                $publicReadbacks[] = $id;
                return ['owner_id' => $id, 'current_path' => '/video/reconciled/'];
            },
        );

        $result = $service->reconcile(self::OLD);

        self::assertSame('REBUILT_AND_APPLIED', $result['status'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(ProposalState::SUPERSEDED, $repository->find(self::OLD)?->state);
        self::assertNotSame(self::OLD, $result['replaced_proposal_id']);
        $videoProposal = array_values(array_filter($lifecycle->created, static fn (Proposal $proposal): bool => $proposal->entityType === 'video'))[0];
        self::assertSame(self::VIDEO, $videoProposal->subjectId);
        self::assertNotSame('old-content', $videoProposal->contentFingerprint);
        self::assertNotSame('old-dependency', $videoProposal->dependencyFingerprint);
        self::assertSame([['evidence_id' => $ids['evidence']]], $videoProposal->payload['metadata']['semantic_attachments'][0]['evidence_refs']);
        self::assertSame(['source', 'knowledge', 'evidence', 'video'], $appliedTypes);
        self::assertSame([$ids['video']], $publicReadbacks);

        $second = $service->reconcile(self::OLD);

        self::assertSame('REUSED_CANONICAL', $second['status']);
        self::assertSame($result['replaced_proposal_id'], $second['replaced_proposal_id']);
        self::assertCount(1, array_filter($lifecycle->created, static fn (Proposal $proposal): bool => $proposal->entityType === 'video'));
    }
}

final class RecordingVideoReconciliationLifecycle implements GovernedLifecycle
{
    /** @var list<Proposal> */
    public array $created = [];
    public function __construct(private ProposalRepository $repository) {}
    public function createFromArguments(array $arguments): Proposal
    {
        $id = UuidCodec::newV7();
        $proposal = new Proposal($id, (string) ($arguments['subject_id'] ?? $arguments['entity_type'] ?? 'video'), (string) ($arguments['operation'] ?? 'ingest'), (array) ($arguments['payload'] ?? []), hash('sha256', json_encode($arguments, JSON_THROW_ON_ERROR)), null, hash('sha256', json_encode((array) ($arguments['dependency_ids'] ?? []), JSON_THROW_ON_ERROR)), idempotencyKey: (string) ($arguments['idempotency_key'] ?? $id), targetUuid: isset($arguments['target_uuid']) ? (string) $arguments['target_uuid'] : null, entityType: (string) ($arguments['entity_type'] ?? ''));
        $this->created[] = $proposal;
        return $this->repository->create($proposal);
    }
    public function submit(string $id): Proposal { return $this->save($id, ProposalState::SUBMITTED); }
    public function review(string $id): array { $proposal = $this->repository->find($id); return ['state' => $proposal?->state->value, 'entity_type' => $proposal?->entityType, 'operation' => $proposal?->operation, 'payload' => $proposal?->payload, 'content_fingerprint' => $proposal?->contentFingerprint, 'dependency_fingerprint' => $proposal?->dependencyFingerprint]; }
    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { return $this->save($id, ProposalState::APPROVED, $actor); }
    public function eligibility(string $id): array { return ['proposal_id' => $id, 'ready' => true, 'reasons' => []]; }
    private function save(string $id, ProposalState $state, ?string $actor = null): Proposal { $proposal = $this->repository->find($id); return $this->repository->save($proposal->transition($state, $actor)); }
}

final class AlwaysReadyEligibilityReader implements EligibilityReader
{
    public function isApplied(string $dependencyUuid): bool { return true; }
    public function targetRevision(string $targetUuid): ?int { return 1; }
    public function targetExists(string $targetUuid): bool { return true; }
}

final class EmptyVideoRepository implements VideoRepository
{
    public function findByCanonicalId(string $id): ?Video { return null; }
    public function findByExternalReference(string $platform, string $externalId): ?Video { return null; }
    public function create(Video $video): Video { return $video; }
    public function update(Video $video, int $expectedRevision): Video { return $video; }
    public function list(bool $includeRetired = false): array { return []; }
}

final class EmptySourceRepository implements SourceRepository
{
    public function findByCanonicalId(string $id): ?Source { return null; }
    public function findByStableKey(string $stableKey): ?Source { return null; }
    public function create(Source $source): Source { return $source; }
    public function update(Source $source, int $expectedRevision): Source { return $source; }
    public function list(bool $includeRetired = false): array { return []; }
}

final class EmptyKnowledgeRepository implements KnowledgeRepository
{
    public function findByCanonicalId(string $id): ?KnowledgeClaim { return null; }
    public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
    public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
    public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
    public function list(bool $includeRetired = false): array { return []; }
}

final class EmptyEvidenceRepository implements EvidenceRepository
{
    public function findByCanonicalId(string $id): ?Evidence { return null; }
    public function create(Evidence $evidence): Evidence { return $evidence; }
    public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
    public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
    public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
}
