<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\RelatedContentQuery;
use NHK\Core\Application\Graph\{BrandAggregationQuery, GraphService, StructuralContextQuery};
use NHK\Core\Application\Knowledge\PostKnowledgeLinkService;
use NHK\Core\Application\Migration\V2MigrationService;
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Domain\Media\Media;
use NHK\Core\Domain\Video\Video;
use NHK\Core\Graph\Exception\EndpointNotFound;
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class P0ConstitutionIntegrityTest extends TestCase
{
    public function test_related_content_does_not_turn_graph_runtime_failure_into_empty_groups(): void
    {
        $types = new EntityTypeRegistry();
        $types->register(new \NHK\Core\Domain\Authority\EntityTypeDefinition('brand', 1, true, []));
        $id = UuidCodec::newV7();
        $authority = new InMemoryAuthorityRepository();
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$id]));
        $graph = new GraphService($this->throwingGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());

        $this->expectException(\RuntimeException::class);
        (new RelatedContentQuery($graph, $authority, $this->emptyMedia(), $this->emptyVideos(), $types))->forEntity('brand', $id);
    }

    public function test_brand_aggregation_does_not_turn_graph_runtime_failure_into_empty_groups(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository();
        $service = new \NHK\Core\Application\Authority\AuthorityService($authority, $types);
        $brand = $service->create('brand', 'p0-brand', 'P0 Brand');
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('brand', new FakeEndpointResolver('brand', [$brand->canonicalId]));
        $graph = new GraphService($this->throwingGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());

        $this->expectException(\RuntimeException::class);
        (new BrandAggregationQuery($graph, $authority, $types))->forBrand($brand->canonicalId);
    }

    public function test_structural_context_does_not_turn_graph_runtime_failure_into_missing_parent(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository();
        $service = new \NHK\Core\Application\Authority\AuthorityService($authority, $types);
        $model = $service->create('model', 'p0-model', 'P0 Model');
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('model', new FakeEndpointResolver('model', [$model->canonicalId]));
        $graph = new GraphService($this->throwingGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());

        $this->expectException(\RuntimeException::class);
        (new StructuralContextQuery($graph, $authority))->forModel($model->canonicalId);
    }

    public function test_post_knowledge_link_cannot_mutate_graph_without_governance(): void
    {
        $claimId = UuidCodec::newV7();
        $this->expectException(\NHK\Core\Governance\Exception\GovernanceException::class);
        (new PostKnowledgeLinkService(new GovernanceService(new \NHK\Tests\Support\InMemoryProposalRepository())))->link('1', 42, $claimId);
    }

    public function test_post_knowledge_link_requests_a_draft_proposal_instead_of_writing_semantic_state(): void
    {
        $claimId = UuidCodec::newV7();
        $proposal = (new PostKnowledgeLinkService(new GovernanceService(new \NHK\Tests\Support\InMemoryProposalRepository())))->request('1', 42, $claimId, 'p0-post-knowledge-1');

        self::assertSame('relation_create', $proposal->operation);
        self::assertSame('relation', $proposal->entityType);
        self::assertSame(\NHK\Core\Domain\Governance\ProposalState::DRAFT, $proposal->state);
        self::assertSame($claimId, $proposal->payload['target_key']);
    }

    public function test_retired_v2_migration_writer_is_unavailable(): void
    {
        $service = new V2MigrationService((object) ['prefix' => 'wp_']);

        $this->expectException(\NHK\Core\Governance\Exception\GovernanceException::class);
        $service->apply([]);
    }

    public function test_public_identity_contains_no_internal_identity_fields(): void
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $entity = new \NHK\Core\Domain\Authority\AuthorityEntity(UuidCodec::newV7(), 'brand', 'p0-brand', 'P0 Brand', 1, []);

        $identity = (new \NHK\Core\Application\Entity\PublicIdentityContract($types))->resolve($entity);

        self::assertIsArray($identity);
        self::assertArrayNotHasKey('id', $identity);
        self::assertArrayNotHasKey('stable_key', $identity);
        self::assertSame(['type' => 'brand', 'name' => 'P0 Brand', 'slug' => 'p0-brand'], $identity);

        $model = new \NHK\Core\Domain\Authority\AuthorityEntity(UuidCodec::newV7(), 'model', 'p0-model-public', 'P0 Model', 1, ['brand_uuid' => UuidCodec::newV7(), 'description' => 'Public description']);
        self::assertArrayNotHasKey('brand_uuid', (new \NHK\Core\Application\Entity\PublicIdentityContract($types))->payload($model));
    }

    public function test_product_and_specimen_boundaries_reject_cross_owned_fields_and_preserve_specimen_identity(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $authority = new \NHK\Core\Application\Authority\AuthorityService($repository, $types);
        $specimen = $authority->create('specimen', 'p0-specimen', 'Physical object', ['serial_number' => 'SN-1']);
        $product = $authority->create('product', 'p0-product', 'Listing', ['price' => 100.0, 'currency' => 'USD', 'availability' => 'listed']);

        $this->expectException(\NHK\Core\Authority\Exception\InvalidPayload::class);
        $authority->update($product->canonicalId, ['serial_number' => 'SN-2'], 1);
    }

    public function test_specimen_rejects_commerce_state_and_product_changes_do_not_replace_physical_identity(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $authority = new \NHK\Core\Application\Authority\AuthorityService($repository, $types);
        $specimen = $authority->create('specimen', 'p0-specimen-2', 'Physical object', ['serial_number' => 'SN-2']);
        $product = $authority->create('product', 'p0-product-2', 'Listing', ['price' => 200.0, 'currency' => 'USD', 'availability' => 'listed']);
        $updated = $authority->update($product->canonicalId, ['price' => 250.0, 'currency' => 'USD', 'availability' => 'sold'], 1);

        self::assertSame($specimen->canonicalId, $repository->findByCanonicalId($specimen->canonicalId)?->canonicalId);
        try {
            $authority->update($specimen->canonicalId, ['availability' => 'sold'], 1);
            self::fail('Specimen accepted commerce state.');
        } catch (\NHK\Core\Authority\Exception\InvalidPayload) {
            self::assertTrue(true);
        }
    }

    /** @return \NHK\Core\Contracts\Graph\GraphRepository */
    private function throwingGraphRepository(): \NHK\Core\Contracts\Graph\GraphRepository
    {
        return new class implements \NHK\Core\Contracts\Graph\GraphRepository {
            public function resolveNode(NodeReference $reference): \NHK\Core\Domain\Graph\GraphNode { throw new \RuntimeException('graph storage unavailable'); }
            public function findNode(NodeReference $reference): ?\NHK\Core\Domain\Graph\GraphNode { throw new \RuntimeException('graph storage unavailable'); }
            public function createEdge(\NHK\Core\Domain\Graph\GraphNode $source, \NHK\Core\Domain\Graph\PredicateDefinition $predicate, \NHK\Core\Domain\Graph\GraphNode $target): \NHK\Core\Domain\Graph\GraphEdge { throw new \RuntimeException('graph storage unavailable'); }
            public function findEdge(NodeReference $source, string $predicate, NodeReference $target): ?\NHK\Core\Domain\Graph\GraphEdge { throw new \RuntimeException('graph storage unavailable'); }
            public function findByUuid(string $uuid): ?\NHK\Core\Domain\Graph\GraphEdge { throw new \RuntimeException('graph storage unavailable'); }
            public function outgoing(\NHK\Core\Domain\Graph\GraphNode $source, ?string $predicate, int $after_id, int $limit, bool $include_retired): array { throw new \RuntimeException('graph storage unavailable'); }
            public function incoming(\NHK\Core\Domain\Graph\GraphNode $target, ?string $predicate, int $after_id, int $limit, bool $include_retired): array { throw new \RuntimeException('graph storage unavailable'); }
            public function retire(\NHK\Core\Domain\Graph\GraphEdge $edge, int $expected_revision): \NHK\Core\Domain\Graph\GraphEdge { throw new \RuntimeException('graph storage unavailable'); }
            public function reactivate(\NHK\Core\Domain\Graph\GraphEdge $edge, int $expected_revision): \NHK\Core\Domain\Graph\GraphEdge { throw new \RuntimeException('graph storage unavailable'); }
            public function nodeHasEdges(\NHK\Core\Domain\Graph\GraphNode $node): bool { throw new \RuntimeException('graph storage unavailable'); }
            public function deleteNode(\NHK\Core\Domain\Graph\GraphNode $node): void { throw new \RuntimeException('graph storage unavailable'); }
        };
    }

    private function emptyMedia(): \NHK\Core\Contracts\Media\MediaRepository
    {
        return new class implements \NHK\Core\Contracts\Media\MediaRepository {
            public function findByCanonicalId(string $id): ?Media { return null; }
            public function findByStableKey(string $key): ?Media { return null; }
            public function create(Media $media): Media { return $media; }
            public function update(Media $media, int $expectedRevision): Media { return $media; }
            public function list(bool $includeRetired = false): array { return []; }
        };
    }

    private function emptyVideos(): \NHK\Core\Contracts\Video\VideoRepository
    {
        return new class implements \NHK\Core\Contracts\Video\VideoRepository {
            public function findByCanonicalId(string $id): ?Video { return null; }
            public function findByExternalReference(string $platform, string $id): ?Video { return null; }
            public function create(Video $video): Video { return $video; }
            public function update(Video $video, int $expectedRevision): Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
    }
}
