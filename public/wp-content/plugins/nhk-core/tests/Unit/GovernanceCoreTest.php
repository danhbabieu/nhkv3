<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Contracts\Governance\GovernanceAuthorizer;
use NHK\Core\Domain\Governance\{ApplyAttempt, Proposal, ProposalState};
use NHK\Core\Governance\Exception\ProposalBindingConflict;
use NHK\Core\Governance\Exception\GovernancePermissionDenied;
use NHK\Core\Domain\Governance\DependencyGraph;
use NHK\Core\Governance\Exception\DependencyCycle;
use NHK\Tests\Support\{InMemoryDependencyRepository, InMemoryProposalRepository};
use PHPUnit\Framework\TestCase;

final class GovernanceCoreTest extends TestCase
{
    public function test_apply_attempt_rejects_invalid_identity_state_and_number(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ApplyAttempt('not-a-uuid', 'not-a-proposal', 0, 'unknown');
    }

    public function test_proposal_rejects_malformed_target_uuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Proposal('proposal-1', 'brand', 'rename', ['name' => 'Name'], 'content', 1, 'deps', ProposalState::DRAFT, null, null, null, 'key-1', 1, null, null, 'not-a-uuid', 'brand');
    }

    public function test_approval_binds_content_and_dependency_closure_and_apply_requires_expected_revision(): void
    {
        $service = new GovernanceService($repo = new InMemoryProposalRepository());
        $proposal = $service->create(new Proposal('p1', 'entity-1', 'rename', ['name' => 'New'], 'content-a', 3, 'deps-a', ProposalState::DRAFT, 'author', null, null, 'key-1'));
        self::assertSame($proposal->bindingFingerprint(), $service->create($proposal)->bindingFingerprint());
        $approved = $service->approve('p1', 'content-a', 'deps-a', 'reviewer');
        self::assertSame(ProposalState::APPROVED, $approved->state);
        $this->expectException(ProposalBindingConflict::class);
        $service->markApplied('p1', 4, 'content-a', 'deps-a');
    }

    public function test_changed_content_or_dependencies_cannot_be_approved_or_applied(): void
    {
        $service = new GovernanceService(new InMemoryProposalRepository());
        $service->create(new Proposal('p2', 'entity-1', 'rename', ['name' => 'New'], 'content-a', 1, 'deps-a', ProposalState::DRAFT, null, null, null, 'key-2'));
        $this->expectException(ProposalBindingConflict::class);
        $service->approve('p2', 'content-b', 'deps-a', 'reviewer');
    }

    public function test_approved_proposal_can_be_applied_only_with_the_same_binding(): void
    {
        $service = new GovernanceService(new InMemoryProposalRepository());
        $service->create(new Proposal('p3', 'entity-1', 'rename', ['name' => 'New'], 'content-a', 1, 'deps-a', ProposalState::DRAFT, null, null, null, 'key-3'));
        $service->approve('p3', 'content-a', 'deps-a', 'reviewer');
        self::assertSame(ProposalState::APPLIED, $service->markApplied('p3', 1, 'content-a', 'deps-a')->state);
    }

    public function test_dependency_closure_rejects_direct_and_transitive_cycles(): void
    {
        $graph = new DependencyGraph($repo = new InMemoryDependencyRepository());
        $graph->add('a', 'b'); $graph->add('b', 'c');
        self::assertSame(['b', 'c'], $graph->closure('a'));
        $this->expectException(DependencyCycle::class);
        $graph->add('c', 'a');
    }

    public function test_terminal_states_cannot_be_reopened_and_supersede_requires_replacement(): void
    {
        $proposal = new Proposal('p4', 'entity-1', 'rename', ['name' => 'New'], 'content', 1, 'deps', ProposalState::DRAFT, null, null, null, 'key-4');
        $submitted = $proposal->transition(ProposalState::SUBMITTED, 'author');
        $approved = $submitted->transition(ProposalState::APPROVED, 'reviewer');
        $applied = $approved->transition(ProposalState::APPLIED, 'reviewer');

        $this->expectException(\InvalidArgumentException::class);
        $applied->transition(ProposalState::DRAFT);
    }

    public function test_supersede_requires_a_different_replacement(): void
    {
        $proposal = new Proposal('p5', 'entity-1', 'rename', ['name' => 'New'], 'content', 1, 'deps', ProposalState::DRAFT, null, null, null, 'key-5');

        $this->expectException(\InvalidArgumentException::class);
        $proposal->transition(ProposalState::SUPERSEDED, 'reviewer', null, 'p5');
    }

    public function test_authorizer_denies_unauthorized_proposal_creation(): void
    {
        $authorizer = new class implements GovernanceAuthorizer {
            public function require(string $capability): void
            {
                throw new GovernancePermissionDenied($capability);
            }
        };
        $service = new GovernanceService(new InMemoryProposalRepository(), null, null, $authorizer);

        $this->expectException(GovernancePermissionDenied::class);
        $service->create(new Proposal('p6', 'entity-1', 'rename', ['name' => 'New'], 'content', 1, 'deps', ProposalState::DRAFT, null, null, null, 'key-6'));
    }
}
