<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Governance\AuthorityProposalExecutor;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
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
}
