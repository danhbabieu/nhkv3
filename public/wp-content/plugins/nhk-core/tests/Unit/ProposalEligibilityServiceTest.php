<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Governance\{ProposalEligibilityService, VideoProposalEligibilityEvaluator};
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\{DependencyRepository, EligibilityReader, ProposalRepository};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Domain\Media\MediaUsage;
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

    public function test_video_scope_verifier_mismatch_is_not_collapsed_into_not_approved(): void
    {
        $proposal = new Proposal(self::ID, self::SUBJECT, 'ingest', [
            'canonical_id' => self::SUBJECT,
            'capture_id' => '01a0b2e0-1888-7038-9811-2dd7e7073a27',
            'staging_acceptance' => ['approved' => true],
            'metadata' => [],
        ], 'video-content', null, 'video-dependency', ProposalState::APPROVED, idempotencyKey: 'video-scope-diagnostic', entityType: 'video');
        $service = $this->service($proposal);
        $service->setStagingScopeVerifier(static fn (Proposal $checked): string => 'STAGING_VIDEO_PAYLOAD_MISMATCH');

        self::assertContains('STAGING_VIDEO_PAYLOAD_MISMATCH', $service->check($proposal->id)->reasons);
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

    public function test_relation_create_skips_uuid_target_check_for_typed_wordpress_source(): void
    {
        $relation = new Proposal(self::ID, '1:487', 'relation_create', [
            'source_type' => 'wp_post', 'source_uuid' => '1:487', 'target_type' => 'classification',
            'target_uuid' => self::SUBJECT, 'source_revision' => 1789459547, 'target_revision' => 1,
        ], 'relation-content', null, 'relation-dependency', ProposalState::APPROVED, idempotencyKey: 'wp-post-relation', entityType: 'relation');
        $service = $this->service($relation, relationReader: new class implements EligibilityReader {
            public function isApplied(string $dependencyUuid): bool { return true; }
            public function targetRevision(string $targetUuid): ?int { return $targetUuid === '1:487' ? 1789459547 : 1; }
            public function targetExists(string $targetUuid): bool { throw new \LogicException('typed relation must not use generic targetExists'); }
        });

        self::assertTrue($service->check($relation->id)->ready);
    }

    public function test_media_usage_revision_is_read_from_usage_not_media_or_post_revision(): void
    {
        $usage = new MediaUsage('01a0b452-153e-79a7-81fd-cc7c2bfda2ef', self::SUBJECT, 'wp_post', '1:596', 'featured_primary', revision: 4, placementKey: 'featured_primary');
        $proposal = new Proposal(self::ID, self::SUBJECT, 'replace', [
            'media' => ['id' => self::SUBJECT],
            'target' => ['type' => 'wp_post', 'blog_id' => 1, 'post_id' => 596],
            'usage_id' => $usage->usageId,
            'expected_usage_revision' => 4,
        ], 'media-usage-content', 1, 'media-usage-dependency', ProposalState::APPROVED, targetUuid: null, entityType: 'media');
        $reader = new class implements EligibilityReader {
            public function isApplied(string $dependencyUuid): bool { return true; }
            public function targetRevision(string $targetUuid): ?int { return $targetUuid === '1:596' ? 999 : 9; }
            public function targetExists(string $targetUuid): bool { return true; }
        };
        $usages = new class($usage) implements MediaUsageRepository {
            public function __construct(private MediaUsage $usage) {}
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $mediaId, ?string $role = null): array { return []; }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return $endpointType === 'wp_post' && $endpointKey === '1:596' ? [$this->usage] : []; }
        };
        $service = $this->service($proposal, mediaUsages: $usages, relationReader: $reader);

        self::assertTrue($service->check($proposal->id)->ready);

        $stale = new Proposal(self::ID, self::SUBJECT, 'replace', array_replace($proposal->payload, ['expected_usage_revision' => 3]), 'media-usage-content-stale', 1, 'media-usage-dependency', ProposalState::APPROVED, targetUuid: null, entityType: 'media');
        self::assertContains('TARGET_REVISION_CHANGED', $this->service($stale, mediaUsages: $usages, relationReader: $reader)->check($stale->id)->reasons);
    }

    private function service(Proposal $proposal, ?SubjectResolutionService $subjectResolver = null, ?EligibilityReader $relationReader = null, ?MediaUsageRepository $mediaUsages = null): ProposalEligibilityService
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
        $reader = $relationReader ?? new class implements EligibilityReader {
            public function isApplied(string $dependencyUuid): bool { return true; }
            public function targetRevision(string $targetUuid): ?int { return 1; }
            public function targetExists(string $targetUuid): bool { return true; }
        };
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('variant', 1, true, []));
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('variant', new AuthorityEndpointResolver($types, $authority));
        $evaluator = new VideoProposalEligibilityEvaluator($videos, $endpoints, new PredicateRegistry(), new CanonicalDependencyValidator($claims, $sources, $evidence), $subjectResolver);
        return new ProposalEligibilityService($repository, new DependencyGraph(new class implements DependencyRepository { public function directDependencies(string $proposalId): array { return []; } public function add(string $proposalId, string $dependencyUuid): void {} }), $reader, $evaluator, null, $mediaUsages);
    }

    private function proposal(array $metadata): Proposal
    {
        return new Proposal(self::ID, self::SUBJECT, 'ingest', ['canonical_id' => self::SUBJECT, 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'metadata' => $metadata], 'content-' . self::ID, null, 'dependency-' . self::ID, ProposalState::APPROVED, idempotencyKey: 'video-' . self::ID, entityType: 'video');
    }
}
