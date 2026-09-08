<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Projection\{ClaimClusterer, ClaimProjectionService, ClaimRanker, ClaimScopeResolver, GraphProjectionPolicy, LiveLedgerProjectionBuilder};
use NHK\Core\Domain\Projection\{ProjectionRevision, ProjectionStatus};
use NHK\Core\Contracts\Graph\EndpointResolver;
use NHK\Core\Contracts\Knowledge\KnowledgeRepository;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Domain\Projection\{ClaimProjectionScope, ProjectedClaim};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Infrastructure\Projection\InMemoryProjectionRevisionStore;
use NHK\Tests\Support\InMemoryGraphRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class SemanticClaimProjectionPipelineTest extends TestCase
{
    public function test_resolver_preserves_subject_and_frames_variant_claim_on_model(): void
    {
        $brand = UuidCodec::newV7(); $model = UuidCodec::newV7(); $variant = UuidCodec::newV7();
        $claim = $this->claim('Odo36/10 thường sử dụng côn chữ M.', $variant, 'variant', 'configuration');
        $graphRepo = new InMemoryGraphRepository(); $graph = $this->graph($graphRepo, [$brand, $model, $variant]);
        $graph->create(new NodeReference('variant', $variant), 'variant_of', new NodeReference('model', $model));
        $graph->create(new NodeReference('model', $model), 'model_of', new NodeReference('brand', $brand));
        $resolver = new ClaimScopeResolver($this->claims([$claim]), $graph, new GraphProjectionPolicy(), labelResolver: static fn (NodeReference $node): string => $node->endpoint_key === $variant ? 'Odo36/10' : '');

        $result = $resolver->resolve(new NodeReference('model', $model));
        self::assertCount(1, $result['related']);
        self::assertSame($variant, $result['related'][0]->scope->canonicalSubjectUuid);
        self::assertStringContainsString('Ở biến thể Odo36/10', $result['related'][0]->displayText);
        self::assertStringContainsString('côn chữ M', $result['related'][0]->displayText);
        self::assertStringNotContainsString('Odo36 sử dụng', $result['related'][0]->displayText);
    }

    public function test_private_claim_is_not_resolved_and_cycle_is_bounded(): void
    {
        $model = UuidCodec::newV7(); $variant = UuidCodec::newV7();
        $private = $this->claim('Không công khai.', $variant, 'variant', 'configuration', ['knowledge_status' => 'PRIVATE']);
        $repo = new InMemoryGraphRepository(); $graph = $this->graph($repo, [$model, $variant]);
        $graph->create(new NodeReference('variant', $variant), 'variant_of', new NodeReference('model', $model));
        $graph->create(new NodeReference('model', $model), 'about', new NodeReference('variant', $variant));
        $result = (new ClaimScopeResolver($this->claims([$private]), $graph, new GraphProjectionPolicy()))->resolve(new NodeReference('model', $model));
        self::assertSame([], $result['items']);
    }

    public function test_ledger_groups_claims_and_paginates_without_merging_canonical_claims(): void
    {
        $node = UuidCodec::newV7();
        $claims = [$this->claim('Côn chữ M thường được sử dụng.', $node, 'variant', 'music_and_strike'), $this->claim('Côn chữ M thường xuất hiện.', $node, 'variant', 'music_and_strike'), $this->claim('Nhiều người chơi đánh giá cao về chất âm.', $node, 'variant', 'user_experience')];
        $graph = $this->graph(new InMemoryGraphRepository(), [$node]);
        $resolver = new ClaimScopeResolver($this->claims($claims), $graph, new GraphProjectionPolicy());
        $ledger = (new LiveLedgerProjectionBuilder($resolver))->build(new NodeReference('variant', $node), ['per_section' => 1]);
        self::assertSame(3, $ledger['claim_count']);
        self::assertSame(3, array_sum(array_map(static fn (array $section): int => (int) $section['claim_count'], $ledger['sections'])));
        self::assertSame(3, array_sum(array_map(static fn (array $section): int => (int) $section['total_count'], $ledger['sections'])));
        self::assertTrue($ledger['has_more']);
        $clusterer = new ClaimClusterer();
        $resolved = $resolver->resolve(new NodeReference('variant', $node));
        $clusters = $clusterer->cluster($resolved['items']);
        self::assertCount(2, $clusters);
        self::assertNotSame([], $clusters[0]->supportingClaimIds);
    }

    public function test_published_ledger_remains_public_after_candidate_is_published(): void
    {
        $node = UuidCodec::newV7();
        $graph = $this->graph(new InMemoryGraphRepository(), [$node]);
        $resolver = new ClaimScopeResolver($this->claims([]), $graph, new GraphProjectionPolicy());
        $store = new InMemoryProjectionRevisionStore();
        $service = new ClaimProjectionService(new LiveLedgerProjectionBuilder($resolver), $store);
        $candidate = $store->saveCandidate(new ProjectionRevision($node, 1, ProjectionStatus::CANDIDATE, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), payload: ['ledger' => ['status' => 'available', 'claim_count' => 0, 'sections' => []]]));
        $ready = $store->markReady($node, $candidate->revision);
        $store->publish($node, $ready->revision);

        self::assertSame('available', $service->getLedger($node)['status']);
    }

    private function claim(string $text, string $subject, string $type, string $category, array $extra = []): KnowledgeClaim
    {
        return new KnowledgeClaim(UuidCodec::newV7(), 'claim.' . substr(hash('sha256', $text . $subject), 0, 12), $text, 'fact', ['metadata' => array_merge(['subject_id' => $subject, 'subject_type' => $type, 'projection_category' => $category, 'knowledge_status' => 'APPROVED'], $extra)]);
    }

    private function claims(array $items): KnowledgeRepository
    {
        return new class($items) implements KnowledgeRepository {
            public function __construct(private array $items) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->canonicalId === $id) return $item; return null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { foreach ($this->items as $item) if ($item->stableKey === $stableKey) return $item; return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return $this->items; }
        };
    }

    private function graph(InMemoryGraphRepository $repository, array $ids): GraphService
    {
        $endpoints = new \NHK\Core\Domain\Graph\EndpointTypeRegistry();
        foreach (['brand', 'model', 'variant'] as $type) $endpoints->register($type, new class($type, $ids) implements EndpointResolver {
            public function __construct(private string $type, private array $ids) {}
            public function supports(string $endpoint_type): bool { return $endpoint_type === $this->type; }
            public function exists(NodeReference $reference): bool { return $reference->endpoint_type === $this->type && in_array($reference->endpoint_key, $this->ids, true); }
            public function normalize(NodeReference $reference): NodeReference { return $reference; }
        });
        return new GraphService($repository, $endpoints, new \NHK\Core\Domain\Graph\PredicateRegistry(), new InMemoryAuditSink());
    }
}
