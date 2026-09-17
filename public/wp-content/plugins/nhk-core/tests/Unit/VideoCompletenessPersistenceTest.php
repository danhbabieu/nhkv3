<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Capture\GovernedCaptureContinuationService;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Video\{VideoCompletenessReconciliationService, VideoEditorialGenerator, VideoEditorialResumePlanner, VideoSeoProjection};
use NHK\Core\Contracts\Governance\AutomationPolicyStorage;
use NHK\Core\Contracts\Graph\EndpointResolver;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class VideoCompletenessPersistenceTest extends TestCase
{
    public function test_reconciliation_persists_corrected_completeness_across_fresh_repository_hydration(): void
    {
        $fixture = $this->fixture(true, true);
        $service = new VideoCompletenessReconciliationService($fixture['repository'], $fixture['graph'], $fixture['dependencies']);

        $reconciled = $service->reconcile($fixture['video_id']);
        unset($service, $reconciled);

        $freshRepository = new RestartableVideoRepository($fixture['store']);
        $fresh = $freshRepository->findByCanonicalId($fixture['video_id']);
        self::assertNotNull($fresh);
        self::assertSame($fixture['video_id'], $fresh->canonicalId);
        self::assertSame(2, $fresh->revision);
        self::assertNotContains('NO_SEMANTIC_ATTACHMENT', $fresh->metadata['completeness']['blockers']);
        self::assertCount(1, $fixture['graph_repository']->allEdges(false));
    }

    public function test_reconciliation_preserves_blocker_without_an_active_valid_attachment(): void
    {
        foreach (['missing_relation', 'retired_relation', 'invalid_evidence'] as $case) {
            $fixture = $this->fixture($case !== 'missing_relation', $case !== 'invalid_evidence');
            if ($case === 'retired_relation') {
                $edge = $fixture['graph']->findOutgoing(new NodeReference('video', $fixture['video_id']), 'about')['items'][0];
                $fixture['graph']->retire($edge->edge_uuid, $edge->revision);
            }
            if ($case === 'invalid_evidence') {
                $metadata = $fixture['store']->video->metadata;
                $metadata['semantic_attachments'][0]['evidence_refs'] = [['evidence_id' => UuidCodec::newV7()]];
                $fixture['repository']->replaceMetadata($fixture['video_id'], $metadata);
            }

            $service = new VideoCompletenessReconciliationService($fixture['repository'], $fixture['graph'], $fixture['dependencies']);
            $service->reconcile($fixture['video_id']);
            $fresh = (new RestartableVideoRepository($fixture['store']))->findByCanonicalId($fixture['video_id']);

            self::assertNotNull($fresh, $case);
            self::assertContains('NO_SEMANTIC_ATTACHMENT', $fresh->metadata['completeness']['blockers'], $case);
        }
    }

    public function test_reconciliation_replay_does_not_create_duplicate_video_relation_or_evidence(): void
    {
        $fixture = $this->fixture(true, true);
        $service = new VideoCompletenessReconciliationService($fixture['repository'], $fixture['graph'], $fixture['dependencies']);

        $first = $service->reconcile($fixture['video_id']);
        $second = $service->reconcile($fixture['video_id']);

        self::assertSame(2, $first->revision);
        self::assertSame(2, $second->revision);
        self::assertCount(1, $fixture['repository']->list(true));
        self::assertCount(1, $fixture['graph_repository']->allEdges(false));
        self::assertSame($fixture['evidence_id'], $fixture['evidence']->findByCanonicalId($fixture['evidence_id'])?->canonicalId);
    }

    public function test_reconciliation_reuses_an_inverse_about_edge_without_reporting_missing_attachment(): void
    {
        $fixture = $this->fixture(false, true);
        $fixture['graph']->create(
            new NodeReference('brand', $fixture['target_id']),
            'about',
            new NodeReference('video', $fixture['video_id']),
        );

        $service = new VideoCompletenessReconciliationService($fixture['repository'], $fixture['graph'], $fixture['dependencies']);
        $service->reconcile($fixture['video_id']);

        $fresh = (new RestartableVideoRepository($fixture['store']))->findByCanonicalId($fixture['video_id']);
        self::assertNotNull($fresh);
        self::assertNotContains('NO_SEMANTIC_ATTACHMENT', $fresh->metadata['completeness']['blockers']);
        self::assertCount(1, $fixture['graph_repository']->allEdges(false));
    }

    public function test_existing_stale_video_resume_repairs_persisted_completeness_without_new_owner_or_relation(): void
    {
        $fixture = $this->fixture(true, true);
        $videoId = $fixture['video_id'];
        $governance = $this->createMock(\NHK\Core\Contracts\Governance\GovernedLifecycle::class);
        $resume = new VideoEditorialResumePlanner($fixture['repository'], new VideoEditorialGenerator(), new VideoSeoProjection());
        $reconciliation = new VideoCompletenessReconciliationService($fixture['repository'], $fixture['graph'], $fixture['dependencies']);
        $service = new GovernedCaptureContinuationService(
            $governance,
            static fn (): array => throw new \LogicException('stale completeness repair must not create a new proposal'),
            new GovernanceAutomationPolicyResolver(['video'], new class implements AutomationPolicyStorage {
                public function read(): array { return []; }
                public function write(array $policies): void {}
            }),
            static fn (string $capability): bool => true,
            videoEditorialResume: $resume,
            videoCompleteness: $reconciliation,
        );

        $result = $service->execute('capture-existing', 'resume-video', [
            'existing_capture_continuation' => true,
            'continuation_delta_text' => '',
            'subject_resolution' => ['resolved' => []],
            'interpretation' => [],
            'observations' => [],
            'assets' => [['kind' => 'video', 'video_proposal' => ['entity_type' => 'video', 'operation' => 'ingest', 'payload' => ['canonical_id' => $videoId]]]],
        ], ['resume_children' => ['video']]);

        $fresh = (new RestartableVideoRepository($fixture['store']))->findByCanonicalId($videoId);
        self::assertSame('APPLIED', $result['status']);
        self::assertSame('REUSED_VERIFIED', $result['writes'][0]['status']);
        self::assertTrue($result['writes'][0]['reused']);
        self::assertTrue($result['writes'][0]['canonical_readback']['completeness']['publishable']);
        self::assertNotContains('NO_SEMANTIC_ATTACHMENT', $fresh?->metadata['completeness']['blockers'] ?? []);
        self::assertCount(1, $fixture['repository']->list(true));
        self::assertCount(1, $fixture['graph_repository']->allEdges(false));
    }

    /** @return array<string,mixed> */
    private function fixture(bool $withRelation, bool $validEvidence): array
    {
        $videoId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $claimId = UuidCodec::newV7();
        $sourceId = UuidCodec::newV7();
        $evidenceId = UuidCodec::newV7();
        $store = new RestartableVideoStore();
        $store->metadata = [
            'source' => ['identity_valid' => true, 'availability' => 'available', 'embeddable' => true],
            'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
            'editorial' => ['title' => 'Video', 'summary' => 'Summary', 'body' => 'Body'],
            'category' => ['primary' => ['key' => '01']],
            'embed_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'seo' => ['title' => 'Video', 'description' => 'Summary'],
            'semantic_attachments' => [[
                'predicate' => 'about',
                'target_type' => 'brand',
                'target_uuid' => $targetId,
                'evidence_refs' => [['evidence_id' => $validEvidence ? $evidenceId : UuidCodec::newV7()]],
            ]],
            'completeness' => ['publishable' => false, 'blockers' => ['NO_SEMANTIC_ATTACHMENT'], 'warnings' => []],
        ];
        $store->video = new Video($videoId, 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Video', $store->metadata);
        $repository = new RestartableVideoRepository($store);
        $graphRepository = new InMemoryGraphRepository();
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('video', new class($repository) implements EndpointResolver {
            public function __construct(private VideoRepository $videos) {}
            public function supports(string $endpoint_type): bool { return $endpoint_type === 'video'; }
            public function exists(NodeReference $reference): bool { return $this->videos->findByCanonicalId($reference->endpoint_key) !== null; }
            public function normalize(NodeReference $reference): NodeReference { return $reference; }
        });
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$targetId]));
        $graph = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        if ($withRelation) $graph->create(new NodeReference('video', $videoId), 'about', new NodeReference('brand', $targetId));

        $claims = new KnownKnowledgeRepository($claimId);
        $sources = new KnownSourceRepository($sourceId);
        $evidence = new KnownEvidenceRepository($evidenceId, $claimId, $sourceId);

        return [
            'video_id' => $videoId,
            'target_id' => $targetId,
            'evidence_id' => $evidenceId,
            'store' => $store,
            'repository' => $repository,
            'graph' => $graph,
            'graph_repository' => $graphRepository,
            'evidence' => $evidence,
            'dependencies' => new CanonicalDependencyValidator($claims, $sources, $evidence),
        ];
    }
}

final class RestartableVideoStore
{
    public Video $video;
    public array $metadata = [];
}

final class RestartableVideoRepository implements VideoRepository
{
    public function __construct(private RestartableVideoStore $store) {}
    public function findByCanonicalId(string $id): ?Video
    {
        if (!isset($this->store->video) || $this->store->video->canonicalId !== $id) return null;
        return $this->hydrate();
    }
    public function findByExternalReference(string $platform, string $externalId): ?Video
    {
        return isset($this->store->video) && $this->store->video->platform === $platform && $this->store->video->externalVideoId === $externalId ? $this->hydrate() : null;
    }
    public function create(Video $video): Video { $this->store->video = $video; return $this->hydrate(); }
    public function update(Video $video, int $expectedRevision): Video
    {
        if ($this->store->video->revision !== $expectedRevision) throw new \RuntimeException('Video revision conflict.');
        $this->store->video = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $video->metadata, $video->thumbnailMediaId, $video->active, $expectedRevision + 1);
        return $this->hydrate();
    }
    public function list(bool $includeRetired = false): array { return isset($this->store->video) && ($includeRetired || $this->store->video->active) ? [$this->hydrate()] : []; }
    public function replaceMetadata(string $id, array $metadata): void
    {
        $video = $this->findByCanonicalId($id);
        if ($video === null) throw new \RuntimeException('Video not found.');
        $this->store->video = new Video($video->canonicalId, $video->platform, $video->externalVideoId, $video->canonicalUrl, $video->title, $metadata, $video->thumbnailMediaId, $video->active, $video->revision);
    }
    private function hydrate(): Video
    {
        $row = $this->store->video;
        return new Video($row->canonicalId, $row->platform, $row->externalVideoId, $row->canonicalUrl, $row->title, json_decode(json_encode($row->metadata, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), $row->thumbnailMediaId, $row->active, $row->revision);
    }
}

final class KnownKnowledgeRepository implements KnowledgeRepository
{
    public function __construct(private string $id) {}
    public function findByCanonicalId(string $id): ?KnowledgeClaim { return $id === $this->id ? new KnowledgeClaim($id, 'claim-key', 'Claim', 'fact') : null; }
    public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
    public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
    public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
    public function list(bool $includeRetired = false): array { return []; }
}

final class KnownSourceRepository implements SourceRepository
{
    public function __construct(private string $id) {}
    public function findByCanonicalId(string $id): ?Source { return $id === $this->id ? new Source($id, 'source-key', 'Source', 'website') : null; }
    public function findByStableKey(string $stableKey): ?Source { return null; }
    public function create(Source $source): Source { return $source; }
    public function update(Source $source, int $expectedRevision): Source { return $source; }
    public function list(bool $includeRetired = false): array { return []; }
}

final class KnownEvidenceRepository implements EvidenceRepository
{
    public function __construct(private string $id, private string $claimId, private string $sourceId) {}
    public function findByCanonicalId(string $id): ?Evidence { return $id === $this->id ? new Evidence($id, $this->claimId, $this->sourceId, 'supports', 'Evidence excerpt') : null; }
    public function create(Evidence $evidence): Evidence { return $evidence; }
    public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
    public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
    public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
}
