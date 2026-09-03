<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\{EligibilityReader, ProposalRepository};
use NHK\Core\Domain\Governance\{DependencyGraph, EligibilityResult, ProposalState};

final class ProposalEligibilityService
{
    public function __construct(private ProposalRepository $proposals, private DependencyGraph $dependencies, private EligibilityReader $reader) {}

    public function check(string $proposalId): EligibilityResult
    {
        $proposal = $this->proposals->find($proposalId);
        if ($proposal === null) return EligibilityResult::blocked('PROPOSAL_NOT_FOUND');
        if ($proposal->state === ProposalState::APPLIED) return EligibilityResult::blocked('ALREADY_APPLIED');
        if (!in_array($proposal->state, [ProposalState::SUBMITTED, ProposalState::APPROVED], true)) return EligibilityResult::blocked('NOT_APPROVED');
        if ($proposal->state !== ProposalState::APPROVED) return EligibilityResult::blocked('APPROVAL_MISSING');
        $approval = $this->proposals->latestApproval($proposalId);
        if ($approval === null) return EligibilityResult::blocked('APPROVAL_MISSING');
        if ((int) ($approval['proposal_revision'] ?? 0) !== $proposal->revision
            || $this->fingerprint($approval['fingerprint'] ?? null) !== strtolower($proposal->bindingFingerprint())) {
            return EligibilityResult::blocked('APPROVAL_BINDING_MISMATCH');
        }
        $reasons = [];
        $isCreation = in_array($proposal->operation, ['create', 'ingest', 'relation_create'], true) && $proposal->targetUuid === null;
        if (!$isCreation && $proposal->subjectId !== '' && !$this->reader->targetExists($proposal->targetUuid ?: $proposal->subjectId)) $reasons[] = 'TARGET_NOT_FOUND';
        if ($proposal->operation === 'merge') {
            $sourceRevision = (int) ($proposal->payload['source_revision'] ?? $proposal->expectedRevision);
            $targetRevision = (int) ($proposal->payload['target_revision'] ?? 0);
            if ($sourceRevision < 1 || $targetRevision < 1) $reasons[] = 'MERGE_REVISIONS_REQUIRED';
            if ($proposal->subjectId !== '' && $this->reader->targetRevision($proposal->subjectId) !== $sourceRevision) $reasons[] = 'SOURCE_REVISION_CHANGED';
            if ($proposal->targetUuid !== null && $this->reader->targetRevision($proposal->targetUuid) !== $targetRevision) $reasons[] = 'TARGET_REVISION_CHANGED';
        } elseif (!$isCreation && $proposal->subjectId !== '' && $proposal->expectedRevision > 0 && $this->reader->targetRevision($proposal->targetUuid ?: $proposal->subjectId) !== $proposal->expectedRevision) $reasons[] = 'TARGET_REVISION_CHANGED';
        foreach ($this->dependencies->closure($proposalId) as $dependency) if (!$this->reader->isApplied($dependency)) $reasons[] = 'DEPENDENCY_NOT_APPLIED';
        return $reasons ? EligibilityResult::blocked(...$reasons) : EligibilityResult::ready();
    }

    private function fingerprint(mixed $value): string
    {
        if (!is_string($value)) return '';
        return preg_match('/^[a-f0-9]{64}$/i', $value) === 1
            ? strtolower($value)
            : bin2hex($value);
    }
}
