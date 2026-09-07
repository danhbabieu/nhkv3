<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\{AuthorityProposalExecutor, GovernanceService};
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Video\VideoService;
use NHK\Core\Application\Video\VideoCompletenessPolicy;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Contracts\Governance\ApprovedRelationProposalRepository;
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Domain\Video\VideoRelationCandidate;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository, InMemoryProposalRepository};
use PHPUnit\Framework\TestCase;

final class VideoRelationLifecycleTest extends TestCase
{
    public function test_completeness_still_blocks_a_video_without_semantic_attachment(): void
    {
        $result = (new VideoCompletenessPolicy())->evaluate([
            'source' => ['identity_valid' => true, 'availability' => 'available', 'embeddable' => true],
            'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
            'editorial' => ['title' => 'T', 'summary' => 'S', 'body' => 'B'],
            'category' => ['primary' => ['key' => '01']],
            'semantic_attachments' => [],
            'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'seo' => ['title' => 'T', 'description' => 'S'],
        ]);

        self::assertFalse($result->publishable);
        self::assertContains('NO_SEMANTIC_ATTACHMENT', $result->blockers);
    }

    public function test_relation_packet_binds_both_canonical_endpoint_uuids(): void
    {
        $candidate = new VideoRelationCandidate('video', '01a07971-2fe3-77da-9424-998cf6f249e0', '22222222-2222-4222-8222-222222222222', 'brand', 'about', 'EXPLICIT_USER_RELATION', [['evidence_id' => '33333333-3333-4333-8333-333333333333']]);
        $packet = $candidate->toProposalPayload();

        self::assertSame('01a07971-2fe3-77da-9424-998cf6f249e0', $packet['source_uuid']);
        self::assertSame('22222222-2222-4222-8222-222222222222', $packet['target_uuid']);
        self::assertSame('about', $packet['predicate']);
    }

    public function test_video_proposal_replay_is_idempotent_and_fingerprint_bound(): void
    {
        $governance = new GovernanceService(new InMemoryProposalRepository());
        $payload = ['canonical_id' => '01a07971-2fe3-77da-9424-998cf6f249e0', 'url' => 'https://youtu.be/dQw4w9WgXcQ'];
        $first = new Proposal('video-proposal-a', 'video', 'ingest', $payload, 'content-fingerprint', null, 'dependency-fingerprint', ProposalState::DRAFT, idempotencyKey: 'video-replay', entityType: 'video');
        $replay = new Proposal('video-proposal-b', 'video', 'ingest', $payload, 'content-fingerprint', null, 'dependency-fingerprint', ProposalState::DRAFT, idempotencyKey: 'video-replay', entityType: 'video');

        self::assertSame($first->id, $governance->create($first)->id);
        self::assertSame($first->id, $governance->create($replay)->id);

        $changedFingerprint = new Proposal('video-proposal-c', 'video', 'ingest', $payload, 'changed-content-fingerprint', null, 'dependency-fingerprint', ProposalState::DRAFT, idempotencyKey: 'video-replay', entityType: 'video');
        $this->expectExceptionMessage('Idempotency key is already bound to different content.');
        $governance->create($changedFingerprint);
    }

    public function test_independent_relation_apply_keeps_endpoint_validation_for_unmaterialized_video(): void
    {
        $videoId = '01a07971-2fe3-77da-9424-998cf6f249e0';
        $targetId = '22222222-2222-4222-8222-222222222222';
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('video', new FakeEndpointResolver('video', []));
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$targetId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $executor = new AuthorityProposalExecutor(new AuthorityService(new InMemoryAuthorityRepository(), new EntityTypeRegistry()), $graph);

        $this->expectExceptionMessage('Endpoint does not exist: video:' . $videoId);
        $executor(new Proposal('relation-before-video', 'relation', 'relation_create', [
            'source_type' => 'video', 'source_uuid' => $videoId, 'target_type' => 'brand', 'target_uuid' => $targetId, 'predicate' => 'about',
        ], 'content', null, 'deps', ProposalState::APPROVED, idempotencyKey: 'relation-before-video', entityType: 'relation'));
    }

    public function test_new_video_is_not_public_until_approved_relation_is_materialized(): void
    {
        $videoId = '01a07971-2fe3-77da-9424-998cf6f249e0';
        $targetId = '22222222-2222-4222-8222-222222222222';
        $evidenceId = '33333333-3333-4333-8333-333333333333';
        $videos = new class implements VideoRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Video { return $this->items[$id] ?? null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { foreach ($this->items as $video) if ($video->platform === $platform && $video->externalVideoId === $externalId) return $video; return null; }
            public function create(Video $video): Video { return $this->items[$video->canonicalId] = $video; }
            public function update(Video $video, int $expectedRevision): Video { return $this->items[$video->canonicalId] = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $video->metadata, $video->thumbnailMediaId, $video->active, $expectedRevision + 1); }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $claims = new class($targetId) implements KnowledgeRepository {
            public function __construct(private string $id) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return $id === $this->id ? new KnowledgeClaim($id, 'claim', 'A claim.', 'fact') : null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $sources = new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return new Source($id, 'test-source', 'Test source', 'website', 'https://example.test'); }
            public function findByStableKey(string $stableKey): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $evidence = new class($evidenceId, $targetId) implements EvidenceRepository {
            public function __construct(private string $evidenceId, private string $claimId) {}
            public function findByCanonicalId(string $id): ?Evidence { return $id === $this->evidenceId ? new Evidence($id, $this->claimId, '44444444-4444-4444-8444-444444444444', 'supports', 'Supported excerpt.') : null; }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $dependencyValidator = new CanonicalDependencyValidator($claims, $sources, $evidence);
        $endpointStateAtCreate = null;
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('video', new class($videos, $videoId, $endpointStateAtCreate) implements \NHK\Core\Contracts\Graph\EndpointResolver {
            public function __construct(private VideoRepository $videos, private string $id, private mixed &$state) {}
            public function supports(string $endpoint_type): bool { return $endpoint_type === 'video'; }
            public function exists(NodeReference $reference): bool { if ($reference->endpoint_key === $this->id) $this->state = $this->videos->findByCanonicalId($this->id)?->active; return $this->videos->findByCanonicalId($reference->endpoint_key) !== null; }
            public function normalize(NodeReference $reference): NodeReference { return $reference; }
        });
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$targetId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $executor = new AuthorityProposalExecutor(new AuthorityService(new InMemoryAuthorityRepository(), new EntityTypeRegistry()), $graph, null, new VideoService($videos), null, null, null, null, $dependencyValidator);
        $video = $executor(new Proposal('video-lifecycle', 'video', 'ingest', [
            'canonical_id' => $videoId,
            'url' => 'https://youtu.be/dQw4w9WgXcQ',
            'title' => 'Video lifecycle',
            'metadata' => [
                'intake_version' => 1,
                // Intake completeness is evaluated before the relation exists;
                // this stale blocker must not prevent relation materialization.
                'completeness' => ['blockers' => ['NO_SEMANTIC_ATTACHMENT']],
                'source' => ['identity_valid' => true, 'availability' => 'available', 'embeddable' => true],
                'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
                'editorial' => ['title' => 'Video lifecycle', 'summary' => 'Summary', 'body' => 'Body'],
                'category' => ['primary' => ['key' => '01']],
                'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
                'seo' => ['title' => 'Video lifecycle', 'description' => 'Summary'],
                'semantic_attachments' => [[
                    'target_type' => 'brand', 'target_uuid' => $targetId, 'predicate' => 'about',
                    'evidence_refs' => [['evidence_id' => $evidenceId]],
                ]],
            ],
        ], 'content', null, 'deps', ProposalState::APPROVED, idempotencyKey: 'video-lifecycle', entityType: 'video'));

        self::assertFalse((bool) $endpointStateAtCreate);
        self::assertTrue($video->active);
        self::assertSame($videoId, $videos->findByCanonicalId($videoId)?->canonicalId);
    }

    public function test_historical_video_proposal_discovers_approved_bound_relation(): void
    {
        $videoId = '01a07971-2fe3-77da-9424-998cf6f249e0';
        $targetId = '22222222-2222-4222-8222-222222222222';
        $secondTargetId = '44444444-4444-4444-8444-444444444444';
        $relations = new class($videoId, $targetId, $secondTargetId) implements ApprovedRelationProposalRepository {
            public function __construct(private string $videoId, private string $targetId, private string $secondTargetId) {}
            public function findApprovedFingerprintBoundRelations(string $sourceType, string $sourceUuid, string $sourceFingerprint): array
            {
                return [new Proposal('relation-a', 'relation', 'relation_create', [
                    'source_type' => 'video', 'source_uuid' => $this->videoId,
                    'target_type' => 'brand', 'target_uuid' => $this->targetId, 'predicate' => 'about',
                    'evidence_refs' => [['evidence_id' => '33333333-3333-4333-8333-333333333333']],
                ], 'relation-a-fingerprint', null, 'deps', ProposalState::APPROVED, decisionActor: 'reviewer', entityType: 'relation'), new Proposal('relation-b', 'relation', 'relation_create', [
                    'source_type' => 'video', 'source_uuid' => $this->videoId,
                    'target_type' => 'brand', 'target_uuid' => $this->secondTargetId, 'predicate' => 'about',
                    'evidence_refs' => [['evidence_id' => '33333333-3333-4333-8333-333333333333']],
                ], 'relation-b-fingerprint', null, 'deps', ProposalState::APPROVED, decisionActor: 'reviewer', entityType: 'relation')];
            }
        };
        $videos = new class implements VideoRepository {
            public array $items = [];
            public function findByCanonicalId(string $id): ?Video { return $this->items[$id] ?? null; }
            public function findByExternalReference(string $platform, string $externalId): ?Video { foreach ($this->items as $video) if ($video->platform === $platform && $video->externalVideoId === $externalId) return $video; return null; }
            public function create(Video $video): Video { return $this->items[$video->canonicalId] = $video; }
            public function update(Video $video, int $expectedRevision): Video { return $this->items[$video->canonicalId] = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $video->metadata, $video->thumbnailMediaId, $video->active, $expectedRevision + 1); }
            public function list(bool $includeRetired = false): array { return array_values($this->items); }
        };
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('video', new class($videos) implements \NHK\Core\Contracts\Graph\EndpointResolver {
            public function __construct(private VideoRepository $videos) {}
            public function supports(string $endpoint_type): bool { return $endpoint_type === 'video'; }
            public function exists(NodeReference $reference): bool { return $this->videos->findByCanonicalId($reference->endpoint_key) !== null; }
            public function normalize(NodeReference $reference): NodeReference { return $reference; }
        });
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$targetId, $secondTargetId]));
        $graphRepo = new InMemoryGraphRepository();
        $graph = new GraphService($graphRepo, $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $claims = new class implements KnowledgeRepository {
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return new KnowledgeClaim($id, 'claim', 'Claim', 'fact'); }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $sources = new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?Source { return new Source($id, 'source', 'Source', 'website'); }
            public function findByStableKey(string $stableKey): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $evidence = new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?Evidence { return new Evidence($id, '22222222-2222-4222-8222-222222222222', '44444444-4444-4444-8444-444444444444', 'supports', 'Excerpt'); }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $executor = new AuthorityProposalExecutor(new AuthorityService(new InMemoryAuthorityRepository(), new EntityTypeRegistry()), $graph, null, new VideoService($videos), null, null, null, null, new CanonicalDependencyValidator($claims, $sources, $evidence), null, $relations);

        $video = $executor(new Proposal('video-historical', 'video', 'ingest', [
            'canonical_id' => $videoId, 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'title' => 'Historical video',
            'metadata' => [
                'intake_version' => 1, 'source' => ['identity_valid' => true, 'availability' => 'available', 'embeddable' => true],
                'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE', 'editorial' => ['title' => 'Historical', 'summary' => 'Summary', 'body' => 'Body'],
                'category' => ['primary' => ['key' => '01']], 'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
                'seo' => ['title' => 'Historical', 'description' => 'Summary'], 'semantic_attachments' => [],
            ],
        ], 'video-fingerprint', null, 'deps', ProposalState::APPROVED, entityType: 'video'));

        self::assertTrue($video->active);
        self::assertCount(2, $graphRepo->allEdges());
    }
}
