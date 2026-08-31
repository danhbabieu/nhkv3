<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\{GovernanceAuditSink, GovernanceAuthorizer, ProposalRepository};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Governance\Exception\{InvalidProposalTransition, ProposalBindingConflict, ProposalIdempotencyConflict, ProposalNotFound};
use NHK\Core\Contracts\Shared\TransactionManager;

final class GovernanceService
{
    public function __construct(private ProposalRepository $repository, private ?GovernanceAuditSink $audit = null, private ?TransactionManager $transactions = null, private ?GovernanceAuthorizer $authorizer = null) {}

    public function create(Proposal $proposal): Proposal
    {
        $this->authorizer?->require('nhk_create_proposals');
        if ($proposal->idempotencyKey === '') throw new ProposalBindingConflict('Idempotency key is required.');
        $byKey = $this->repository->findByIdempotencyKey($proposal->idempotencyKey);
        if ($byKey !== null) {
            if ($this->sameCommand($byKey, $proposal)) return $byKey;
            throw new ProposalIdempotencyConflict('Idempotency key is already bound to different content.');
        }
        $existing = $this->repository->find($proposal->id);
        if ($existing !== null) {
            if ($this->sameCommand($existing, $proposal)) return $existing;
            throw new ProposalBindingConflict('Proposal id is already bound to different content.');
        }
        $saved = $this->repository->create($proposal);
        $this->audit?->record('created', $saved);
        return $saved;
    }

    public function submit(string $id): Proposal
    {
        $this->authorizer?->require('nhk_submit_proposals');
        $proposal = $this->get($id);
        if ($proposal->state !== ProposalState::DRAFT) throw new InvalidProposalTransition('Only draft proposals can be submitted.');
        return $this->transition($proposal, ProposalState::SUBMITTED, $proposal->actor, 'submitted');
    }

    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal
    {
        $this->authorizer?->require('nhk_approve_proposals');
        $proposal = $this->get($id);
        $approve = function () use ($id, $contentFingerprint, $dependencyFingerprint, $actor, $proposal): Proposal {
            $proposal = $this->repository->findForUpdate($id) ?? throw new ProposalNotFound('Proposal not found.');
            if (!in_array($proposal->state, [ProposalState::DRAFT, ProposalState::SUBMITTED], true)) throw new InvalidProposalTransition('Only draft or submitted proposals can be approved.');
            if ($proposal->contentFingerprint !== $contentFingerprint || $proposal->dependencyFingerprint !== $dependencyFingerprint) throw new ProposalBindingConflict('Approval binding no longer matches the proposal.');
            $saved = $this->transition($proposal, ProposalState::APPROVED, $actor, 'approved');
            if (method_exists($this->repository, 'recordApproval')) $this->repository->recordApproval($saved, $actor);
            return $saved;
        };
        return $this->transactions ? $this->transactions->transactional($approve) : $approve();
    }

    public function reject(string $id, string $actor): Proposal
    {
        $this->authorizer?->require('nhk_approve_proposals');
        $proposal = $this->get($id);
        if (!in_array($proposal->state, [ProposalState::DRAFT, ProposalState::SUBMITTED], true)) throw new InvalidProposalTransition('Only draft or submitted proposals can be rejected.');
        return $this->transition($proposal, ProposalState::REJECTED, $actor, 'rejected');
    }

    public function cancel(string $id, string $actor): Proposal
    {
        $this->authorizer?->require('nhk_submit_proposals');
        $proposal=$this->get($id);
        if(in_array($proposal->state,[ProposalState::APPLIED,ProposalState::REJECTED,ProposalState::CANCELLED,ProposalState::SUPERSEDED],true))throw new InvalidProposalTransition('Terminal proposals cannot be cancelled.');
        return $this->transition($proposal,ProposalState::CANCELLED,$actor,'cancelled');
    }

    public function supersede(string $id, string $replacementId, string $actor): Proposal
    {
        $this->authorizer?->require('nhk_submit_proposals');
        $proposal=$this->get($id);
        if(in_array($proposal->state,[ProposalState::APPLIED,ProposalState::REJECTED,ProposalState::CANCELLED,ProposalState::SUPERSEDED],true))throw new InvalidProposalTransition('Terminal proposals cannot be superseded.');
        $this->get($replacementId);
        return $this->transition($proposal,ProposalState::SUPERSEDED,$actor,'superseded',$replacementId);
    }

    public function markApplied(string $id, int $actualRevision, string $contentFingerprint, string $dependencyFingerprint): Proposal
    {
        $proposal = $this->get($id);
        if ($proposal->state !== ProposalState::APPROVED) throw new InvalidProposalTransition('Only approved proposals can be applied.');
        if ($actualRevision !== $proposal->expectedRevision || $contentFingerprint !== $proposal->contentFingerprint || $dependencyFingerprint !== $proposal->dependencyFingerprint) {
            throw new ProposalBindingConflict('Approved proposal is stale or has changed dependencies.');
        }
        return $this->transition($proposal, ProposalState::APPLIED, $proposal->decisionActor, 'applied');
    }

    private function get(string $id): Proposal
    {
        return $this->repository->find($id) ?? throw new ProposalNotFound('Proposal not found.');
    }

    private function sameCommand(Proposal $left, Proposal $right): bool
    {
        return $left->bindingFingerprint() === $right->bindingFingerprint()
            && $left->payload === $right->payload;
    }

    private function transition(Proposal $proposal, ProposalState $state, ?string $actor, string $event, ?string $supersededBy = null): Proposal
    {
        $next = $proposal->transition($state, $actor, gmdate('Y-m-d H:i:s.u'), $supersededBy);
        $saved = $this->repository->save($next);
        $this->audit?->record($event, $saved);
        return $saved;
    }
}
