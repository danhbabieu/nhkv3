<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\AuthorityProposalExecutor;
use NHK\Core\Contracts\Graph\AuditSink;
use NHK\Core\Application\Governance\ControlledApplyOperationRegistry;
use NHK\Core\Application\Governance\OperationCompatibilityException;
use NHK\Core\Application\Knowledge\KnowledgeEnrichmentProposalFactory;
use NHK\Core\Domain\Knowledge\{KnowledgeEnrichmentCandidate, KnowledgeFacetProfile};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Graph\Exception\UnapprovedRelationPair;
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\InMemoryGraphRepository;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class GovernanceApplyContractTest extends TestCase
{
    /** @dataProvider governedProductSpecimenDirections */
    public function test_governed_product_specimen_about_relation_fails_closed(string $source, string $target): void
    {
        $types = new EndpointTypeRegistry();
        foreach (['product', 'specimen'] as $type) {
            $types->register($type, new FakeEndpointResolver($type, ['a', 'b']));
        }
        $graph = new GraphService(new InMemoryGraphRepository(), $types, new PredicateRegistry(), new class implements AuditSink {
            public function record(string $event, \NHK\Core\Domain\Graph\GraphEdge $edge): void {}
        });
        $executor = new AuthorityProposalExecutor(new AuthorityService(new InMemoryAuthorityRepository(), new EntityTypeRegistry()), $graph);

        $this->expectException(UnapprovedRelationPair::class);
        $this->expectExceptionMessage('Product–Specimen relation is not approved.');
        $executor(new Proposal(
            'product-specimen-about',
            'relation',
            'relation_create',
            ['source_type' => $source, 'source_key' => 'a', 'predicate' => 'about', 'target_type' => $target, 'target_key' => 'b'],
            'content',
            null,
            'deps',
            ProposalState::APPROVED,
            '1',
            '2',
            null,
            'idem-product-specimen',
            1,
            null,
            null,
            null,
            'relation',
        ));
    }

    public static function governedProductSpecimenDirections(): array
    {
        return [['product', 'specimen'], ['specimen', 'product']];
    }

    public function test_controlled_apply_registry_matches_the_semantic_dispatch_matrix(): void
    {
        $registry = new ControlledApplyOperationRegistry();
        foreach (['knowledge', 'source', 'evidence'] as $entityType) {
            foreach (['create', 'ingest', 'update', 'retire', 'reactivate'] as $operation) self::assertTrue($registry->supports($entityType, $operation), $entityType . '+' . $operation);
            foreach (['relation_create', 'rename'] as $operation) self::assertFalse($registry->supports($entityType, $operation), $entityType . '+' . $operation);
        }
    }

    public function test_controlled_apply_rejects_a_global_but_entity_unsupported_operation_before_dispatch(): void
    {
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $authority = new AuthorityService(new InMemoryAuthorityRepository(), $types);
        $policy = new class implements \NHK\Core\Application\Governance\OperationCompatibility { public function supports(string $entityType, string $operation): bool { return $entityType === 'knowledge' && $operation === 'create'; } };
        $executor = new AuthorityProposalExecutor($authority, operationCompatibility: $policy);
        try {
            $executor(new Proposal('unsupported-knowledge-ingest', 'knowledge', 'ingest', [], 'content', 1, 'deps', ProposalState::APPROVED, '1', '2', null, 'idem', 1, null, null, null, 'knowledge'));
            self::fail('Expected a registry gap.');
        } catch (OperationCompatibilityException $error) {
            self::assertSame('REGISTRY_GAP', $error->diagnosticCode);
        }
    }

    public function test_factory_and_controlled_apply_can_share_one_compatibility_source(): void
    {
        $policy = new class implements \NHK\Core\Application\Governance\OperationCompatibility {
            public array $calls = [];
            public function supports(string $entityType, string $operation): bool { $this->calls[] = [$entityType, $operation]; return $entityType === 'knowledge' && $operation === 'create'; }
        };
        $candidate = new KnowledgeEnrichmentCandidate('new_claim', UuidCodec::newV7(), new KnowledgeFacetProfile('music', 'movement'), 'Cọc trắng.');
        self::assertSame('create', (new KnowledgeEnrichmentProposalFactory(['ingest', 'create'], $policy))->arguments($candidate, 'run')['operation']);
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, []));
        try {
            (new AuthorityProposalExecutor(new AuthorityService(new InMemoryAuthorityRepository(), $types), operationCompatibility: $policy))(new Proposal('unsupported-knowledge-ingest', 'knowledge', 'ingest', [], 'content', 1, 'deps', ProposalState::APPROVED, '1', '2', null, 'idem', 1, null, null, null, 'knowledge'));
            self::fail('Expected a registry gap.');
        } catch (OperationCompatibilityException $error) {
            self::assertSame('REGISTRY_GAP', $error->diagnosticCode);
        }
        self::assertContains(['knowledge', 'create'], $policy->calls);
        self::assertContains(['knowledge', 'ingest'], $policy->calls);
    }

    public function test_authority_executor_applies_create_and_preserves_the_canonical_identity_on_rename(): void
    {
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, ['description'], [], ['description' => 'string']));
        $authority = new AuthorityService($repository = new InMemoryAuthorityRepository(), $types);
        $executor = new AuthorityProposalExecutor($authority);
        $created = $executor(new Proposal('create-1', 'brand', 'create', ['stable_key' => 'odo', 'name' => 'Odo', 'entity_payload' => ['description' => 'Original']], 'content', 1, 'deps', ProposalState::APPROVED, '1', '2', null, 'idem-create', 1, null, null, null, 'brand'));
        self::assertSame('brand', $created->entityType);
        $renamed = $executor(new Proposal('rename-1', $created->canonicalId, 'rename', ['name' => 'Odo & Co.'], 'content', 1, 'deps', ProposalState::APPROVED, '1', '2', null, 'idem-rename', 1, null, null, $created->canonicalId, 'brand'));
        self::assertSame($created->canonicalId, $renamed->canonicalId);
        self::assertSame('Odo & Co.', $repository->findByCanonicalId($created->canonicalId)?->canonicalName);
        self::assertSame(2, $renamed->revision);
    }

    public function test_relation_proposals_are_executed_by_graph_service_with_revisioned_lifecycle(): void
    {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $authority = new AuthorityService(new InMemoryAuthorityRepository(), $types);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:42']));
        $endpoints->register('brand', new FakeEndpointResolver('brand', ['odo']));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $executor = new AuthorityProposalExecutor($authority, $graph);

        $edge = $executor(new Proposal(
            'relation-create-1',
            'relation',
            'relation_create',
            ['source_type' => 'wp_post', 'source_key' => '1:42', 'predicate' => 'about', 'target_type' => 'brand', 'target_key' => 'odo'],
            'content',
            1,
            'deps',
            ProposalState::APPROVED,
            '1',
            '2',
            null,
            'idem-relation-create',
            1,
            null,
            null,
            null,
            'relation',
        ));
        self::assertSame('about', $edge->predicate);
        $retired = $executor(new Proposal('relation-retire-1', $edge->edge_uuid, 'relation_retire', [], 'content', 1, 'deps', ProposalState::APPROVED, '1', '2', null, 'idem-relation-retire', 1, null, null, $edge->edge_uuid, 'relation'));
        self::assertFalse($retired->isActive());
        $reactivated = $executor(new Proposal('relation-reactivate-1', $edge->edge_uuid, 'relation_reactivate', [], 'content', 2, 'deps', ProposalState::APPROVED, '1', '2', null, 'idem-relation-reactivate', 1, null, null, $edge->edge_uuid, 'relation'));
        self::assertTrue($reactivated->isActive());
    }

    public function test_authority_executor_applies_generic_rekey_without_recreating_the_entity(): void
    {
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, ['description'], [], ['description' => 'string']));
        $authority = new AuthorityService($repository = new InMemoryAuthorityRepository(), $types);
        $entity = $authority->create('brand', 'odo', 'Odo', ['description' => 'Original']);
        $executor = new AuthorityProposalExecutor($authority);

        $rekeyed = $executor(new Proposal('rekey-1', $entity->canonicalId, 'rekey', ['old_stable_key' => 'odo', 'new_stable_key' => 'nhk:brand:odo'], 'content', 1, 'deps', ProposalState::APPROVED, '1', '2', null, 'idem-rekey', 1, null, null, $entity->canonicalId, 'brand'));

        self::assertSame($entity->canonicalId, $rekeyed->canonicalId);
        self::assertSame('nhk:brand:odo', $rekeyed->stableKey);
        self::assertSame('Odo', $rekeyed->canonicalName);
        self::assertSame(['description' => 'Original'], $rekeyed->payload);
        self::assertSame(2, $rekeyed->revision);
        self::assertNull($repository->findByStableKey('brand', 'odo'));
    }
}
