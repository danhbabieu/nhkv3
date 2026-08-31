<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Governance\Exception\ProposalBindingConflict;
use NHK\Tests\Support\InMemoryProposalRepository;
use PHPUnit\Framework\TestCase;

final class GovernanceCoreTest extends TestCase
{
    public function test_approval_binds_content_and_dependency_closure_and_apply_requires_expected_revision(): void
    {
        $service = new GovernanceService($repo = new InMemoryProposalRepository());
        $proposal = $service->create(new Proposal('p1', 'entity-1', 'rename', ['name' => 'New'], 'content-a', 3, 'deps-a', ProposalState::DRAFT, 'author'));
        self::assertSame($proposal->bindingFingerprint(), $service->create($proposal)->bindingFingerprint());
        $approved = $service->approve('p1', 'content-a', 'deps-a', 'reviewer');
        self::assertSame(ProposalState::APPROVED, $approved->state);
        $this->expectException(ProposalBindingConflict::class);
        $service->markApplied('p1', 4, 'content-a', 'deps-a');
    }

    public function test_changed_content_or_dependencies_cannot_be_approved_or_applied(): void
    {
        $service = new GovernanceService(new InMemoryProposalRepository());
        $service->create(new Proposal('p2', 'entity-1', 'rename', ['name' => 'New'], 'content-a', 1, 'deps-a'));
        $this->expectException(ProposalBindingConflict::class);
        $service->approve('p2', 'content-b', 'deps-a', 'reviewer');
    }

    public function test_approved_proposal_can_be_applied_only_with_the_same_binding(): void
    {
        $service = new GovernanceService(new InMemoryProposalRepository());
        $service->create(new Proposal('p3', 'entity-1', 'rename', ['name' => 'New'], 'content-a', 1, 'deps-a'));
        $service->approve('p3', 'content-a', 'deps-a', 'reviewer');
        self::assertSame(ProposalState::APPLIED, $service->markApplied('p3', 1, 'content-a', 'deps-a')->state);
    }
}
