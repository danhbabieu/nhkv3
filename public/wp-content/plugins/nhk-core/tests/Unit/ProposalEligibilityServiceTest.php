<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Governance\{ProposalEligibilityService, VideoProposalEligibilityEvaluator};
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\{DependencyRepository, EligibilityReader, ProposalRepository};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Governance\{DependencyGraph, Proposal, ProposalState};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\AuthorityEndpointResolver;
use PHPUnit\Framework\TestCase;

final class ProposalEligibilityServiceTest extends TestCase
{
    private const ID = '018f2f1e-7b2c-7abc-8def-0123456789ab';
    private const SUBJECT = '852da54d-457a-4397-a16d-52d9452ba766';

    public function test_video_ingest_without_semantic_attachment_is_not_ready(): void
    {
        $proposal = $this->proposal(['semantic_attachments' => []]);
        $service = $this->service($proposal);

        self::assertFalse($service->check($proposal->id)->ready);
        self::assertSame(['NO_SEMANTIC_ATTACHMENT'], $service->check($proposal->id)->reasons);
    }

    public function test_legacy_user_hint_evidence_is_not_eligible(): void
    {
        $proposal = $this->proposal([
            'subject_resolution_packet' => ['id' => self::SUBJECT, 'type' => 'variant', 'name' => 'Odo 36/8'],
            'semantic_attachments' => [[
                'target_type' => 'variant',
                'target_uuid' => self::SUBJECT,
                'predicate' => 'about',
                'evidence_refs' => [['kind' => 'USER_HINT', 'value' => 'Số 67']],
            ]],
        ]);
        $service = $this->service($proposal);

        self::assertFalse($service->check($proposal->id)->ready);
        self::assertSame(['CANONICAL_EVIDENCE_REQUIRED'], $service->check($proposal->id)->reasons);
    }

    public function test_explicit_video_subject_packet_is_authoritative_over_unresolved_title_hint(): void
    {
        $proposal = $this->proposal([
            'source' => [
                'platform' => 'youtube',
                'external_video_id' => 'dQw4w9WgXcQ',
                'source_title' => 'title without a canonical match',
            ],
            'subject_resolution_packet' => ['id' => self::SUBJECT, 'type' => 'variant', 'name' => 'Odo 36/8'],
            'semantic_attachments' => [[
                'target_type' => 'variant',
                'target_uuid' => self::SUBJECT,
                'predicate' => 'about',
                'evidence_refs' => [['kind' => 'USER_HINT', 'value' => 'legacy']],
            ]],
        ]);
        $resolver = new SubjectResolutionService(static fn (string $hint): array => []);
        $reasons = $this->service($proposal, $resolver)->check($proposal->id)->reasons;

        self::assertNotContains('SUBJECT_UNRESOLVED', $reasons);
    }

    private function service(Proposal $proposal, ?SubjectResolutionService $subjectResolver = null): ProposalEligibilityService
    {
        $repository = new class($proposal) implements ProposalRepository {
            public function __construct(private Proposal $proposal) {}
            public function create(Proposal $proposal): Proposal { return $proposal; }
            public function find(string $id): ?Proposal { return $id === $this->proposal->id ? $this->proposal : null; }
            public function findByIdempotencyKey(string $key): ?Proposal { return null; }
            public function save(Proposal $proposal): Proposal { return $this->proposal = $proposal; }
            public function findForUpdate(string $id): ?Proposal { return $this->find($id); }
            public function recordApproval(Proposal $proposal, string $actor): void {}
            public function latestApproval(string $proposalId): ?array { return ['proposal_revision' => $this->proposal->revision, 'fingerprint' => hex2bin($this->proposal->bindingFingerprint())]; }
            public function findLatestVideoIngest(string $videoId): ?Proposal { return null; }
        };
        $videos = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Video\Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?\NHK\Core\Domain\Video\Video { return null; }
            public function create(\NHK\Core\Domain\Video\Video $video): \NHK\Core\Domain\Video\Video { return $video; }
            public function update(\NHK\Core\Domain\Video\Video $video, int $expectedRevision): \NHK\Core\Domain\Video\Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $authority = new class implements AuthorityRepository {
            public function findByCanonicalId(string $id): ?AuthorityEntity { return new AuthorityEntity($id, 'variant', 'nhk:variant:odo.36.8', 'Odo 36/8', 1, [], revision: 2); }
            public function findByStableKey(string $type, string $key): ?AuthorityEntity { return null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
            public function listByType(string $type, bool $includeRetired = false): array { return []; }
        };
        $claims = new class implements KnowledgeRepository {
            public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Knowledge\KnowledgeClaim { return null; }
            public function findByStableKey(string $stableKey): ?\NHK\Core\Domain\Knowledge\KnowledgeClaim { return null; }
            public function create(\NHK\Core\Domain\Knowledge\KnowledgeClaim $claim): \NHK\Core\Domain\Knowledge\KnowledgeClaim { return $claim; }
            public function update(\NHK\Core\Domain\Knowledge\KnowledgeClaim $claim, int $expectedRevision): \NHK\Core\Domain\Knowledge\KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $sources = new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Knowledge\Source { return null; }
            public function findByStableKey(string $stableKey): ?\NHK\Core\Domain\Knowledge\Source { return null; }
            public function create(\NHK\Core\Domain\Knowledge\Source $source): \NHK\Core\Domain\Knowledge\Source { return $source; }
            public function update(\NHK\Core\Domain\Knowledge\Source $source, int $expectedRevision): \NHK\Core\Domain\Knowledge\Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $evidence = new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Knowledge\Evidence { return null; }
            public function create(\NHK\Core\Domain\Knowledge\Evidence $evidence): \NHK\Core\Domain\Knowledge\Evidence { return $evidence; }
            public function update(\NHK\Core\Domain\Knowledge\Evidence $evidence, int $expectedRevision): \NHK\Core\Domain\Knowledge\Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $reader = new class implements EligibilityReader {
            public function isApplied(string $dependencyUuid): bool { return true; }
            public function targetRevision(string $targetUuid): ?int { return 1; }
            public function targetExists(string $targetUuid): bool { return true; }
        };
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('variant', 1, true, []));
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('variant', new AuthorityEndpointResolver($types, $authority));
        $evaluator = new VideoProposalEligibilityEvaluator($videos, $endpoints, new PredicateRegistry(), new CanonicalDependencyValidator($claims, $sources, $evidence), $subjectResolver);
        return new ProposalEligibilityService($repository, new DependencyGraph(new class implements DependencyRepository { public function directDependencies(string $proposalId): array { return []; } public function add(string $proposalId, string $dependencyUuid): void {} }), $reader, $evaluator);
    }

    private function proposal(array $metadata): Proposal
    {
        return new Proposal(self::ID, self::SUBJECT, 'ingest', ['canonical_id' => self::SUBJECT, 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'metadata' => $metadata], 'content-' . self::ID, null, 'dependency-' . self::ID, ProposalState::APPROVED, idempotencyKey: 'video-' . self::ID, entityType: 'video');
    }
}
