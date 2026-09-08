<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Projection\{ClaimClusterer, ClaimProjectionService, ClaimRanker, ClaimScopeResolver, GraphProjectionPolicy, LiveLedgerProjectionBuilder, ProjectionBackfillService, SeoProjectionBuilder};
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
        $brand = UuidCodec::newV7(); $model = 'c01c109c-5d39-401e-a16e-6d61a0a52f50'; $variant = '95873bfe-d978-4eda-a5a2-ce9ba79625df';
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

        $seo = (new SeoProjectionBuilder())->build([
            'node_uuid' => $model,
            'sections' => [['key' => 'configuration', 'label' => 'Cấu hình', 'claims' => [['display_text' => $result['related'][0]->displayText, 'status' => 'APPROVED']]]],
        ], '/mau/odo36/', 'Odo36');
        self::assertStringContainsString('Ở biến thể Odo36/10', $seo['sections']['configuration']['content']);
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

    public function test_claim_invalidation_follows_child_to_parent_projection_paths(): void
    {
        $brand = UuidCodec::newV7(); $model = UuidCodec::newV7(); $variant = UuidCodec::newV7();
        $claim = $this->claim('Odo36/10 thường sử dụng côn chữ M.', $variant, 'variant', 'configuration');
        $repository = new InMemoryGraphRepository(); $graph = $this->graph($repository, [$brand, $model, $variant]);
        $graph->create(new NodeReference('variant', $variant), 'variant_of', new NodeReference('model', $model));
        $graph->create(new NodeReference('model', $model), 'model_of', new NodeReference('brand', $brand));
        $resolver = new ClaimScopeResolver($this->claims([$claim]), $graph, new GraphProjectionPolicy());

        $impacted = $resolver->impactNodesForClaim($claim->canonicalId);
        $keys = array_map(static fn (array $item): string => (string) ($item['node_type'] ?? '') . ':' . (string) ($item['node_uuid'] ?? ''), $impacted);

        self::assertContains('variant:' . $variant, $keys);
        self::assertContains('model:' . $model, $keys);
        self::assertContains('brand:' . $brand, $keys);
    }

    public function test_canonical_odo36_fixture_claims_are_direct_ledger_items(): void
    {
        $model = 'c01c109c-5d39-401e-a16e-6d61a0a52f50';
        $claims = [
            new KnowledgeClaim('01a07e84-a8d9-7707-8f0d-ddd98f1b064f', 'nhk:knowledge:odo36.dial.ellipse-logo', 'Odo36 và Odo30 mặt xoáy thường gặp logo elip trên mặt số.', 'fact', ['metadata' => ['subject_id' => $model, 'subject_type' => 'model', 'projection_category' => 'identification_rule', 'knowledge_status' => 'APPROVED']]),
            new KnowledgeClaim('01a07f5d-e0ce-7cb7-ac7a-fc853e6deb47', 'nhk:knowledge:odo36.dial.20x20', 'Mặt số in 20x20 là loại phổ biến trên Odo36, gặp ở 36/8 và 36/10; thường đi kèm kim tháp, kim bút hoặc kim số 8.', 'fact', ['metadata' => ['subject_id' => $model, 'subject_type' => 'model', 'projection_category' => 'dial_and_hands', 'knowledge_status' => 'APPROVED']]),
        ];
        $graph = $this->graph(new InMemoryGraphRepository(), [$model]);
        $result = (new ClaimScopeResolver($this->claims($claims), $graph, new GraphProjectionPolicy()))->resolve(new NodeReference('model', $model));

        self::assertSame('available', $result['status']);
        self::assertCount(2, $result['direct']);
        self::assertSame([$claims[0]->canonicalId, $claims[1]->canonicalId], array_map(static fn (ProjectedClaim $item): string => $item->claim->canonicalId, $result['direct']));
        self::assertSame([], $result['related']);

        $ledger = (new LiveLedgerProjectionBuilder(new ClaimScopeResolver($this->claims($claims), $graph, new GraphProjectionPolicy())))->build(new NodeReference('model', $model));
        self::assertSame(2, $ledger['claim_count']);
        self::assertSame(['dial_and_hands', 'identification_rule'], array_values(array_map(static fn (array $section): string => $section['key'], $ledger['sections'])));
    }

    public function test_relation_invalidation_targets_the_relation_target_and_its_parents_not_the_child(): void
    {
        $brand = UuidCodec::newV7(); $model = UuidCodec::newV7(); $variant = UuidCodec::newV7();
        $repository = new InMemoryGraphRepository(); $graph = $this->graph($repository, [$brand, $model, $variant]);
        $edge = $graph->create(new NodeReference('variant', $variant), 'variant_of', new NodeReference('model', $model));
        $graph->create(new NodeReference('model', $model), 'model_of', new NodeReference('brand', $brand));
        $resolver = new ClaimScopeResolver($this->claims([]), $graph, new GraphProjectionPolicy());

        $impacted = $resolver->impactNodesForRelation($edge->edge_uuid);
        $keys = array_map(static fn (array $item): string => (string) ($item['node_type'] ?? '') . ':' . (string) ($item['node_uuid'] ?? ''), $impacted);

        self::assertContains('model:' . $model, $keys);
        self::assertContains('brand:' . $brand, $keys);
        self::assertNotContains('variant:' . $variant, $keys);
    }

    public function test_backfill_dry_run_is_resumable_and_reports_projection_counts_without_publishing(): void
    {
        $node = UuidCodec::newV7();
        $graph = $this->graph(new InMemoryGraphRepository(), [$node]);
        $resolver = new ClaimScopeResolver($this->claims([]), $graph, new GraphProjectionPolicy());
        $service = new ClaimProjectionService(new LiveLedgerProjectionBuilder($resolver), new InMemoryProjectionRevisionStore());

        $report = (new ProjectionBackfillService($service))->run([
            ['uuid' => $node, 'type' => 'model', 'canonical_url' => '/mau/odo36/', 'h1' => 'Odo 36'],
            ['uuid' => '', 'type' => 'model'],
        ], true, 1, 0);

        self::assertSame('complete', $report['status']);
        self::assertSame(1, $report['scanned']);
        self::assertSame(1, $report['eligible']);
        self::assertSame(0, $report['built']);
        self::assertSame(0, $report['published_count']);
        self::assertSame(1, $report['next_cursor']);
        self::assertSame('would_rebuild', $report['results'][0]['status']);
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
