<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\AuthorityProposalExecutor;
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\InMemoryGraphRepository;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class GovernanceApplyContractTest extends TestCase
{
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
