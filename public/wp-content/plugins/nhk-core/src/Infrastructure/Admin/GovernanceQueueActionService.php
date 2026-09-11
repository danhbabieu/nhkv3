<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Contracts\Governance\{GovernanceActionPort, VideoProposalReconciliationPort};
use NHK\Core\Domain\Governance\{EligibilityResult, Proposal, ProposalState};
use NHK\Core\Governance\Exception\{GovernancePermissionDenied, InvalidProposalTransition, ProposalBindingConflict, ProposalNotFound};
use NHK\Core\Domain\Knowledge\DependencyValidationException;
use NHK\Core\Shared\Uuid\UuidCodec;

final class GovernanceQueueActionService
{
    /** @var array<string, string> */
    private const CAPABILITIES = [
        'submit' => 'nhk_submit_proposals',
        'approve' => 'nhk_approve_proposals',
        'reject' => 'nhk_approve_proposals',
        'apply' => 'nhk_apply_proposals',
    ];

    /** @param callable(string): bool $can  @param callable(): string|int $actor */
    public function __construct(
        private GovernanceActionPort $port,
        private $can,
        private $actor,
        private ?VideoProposalReconciliationPort $videoReconciliation = null,
    ) {}

    /** @return array<string, mixed> */
    public function execute(string $action, string $id, array $snapshot = []): array
    {
        $base = ['ok' => false, 'outcome' => 'failed', 'proposal_id' => $id, 'action' => $action, 'state' => null, 'reason' => null];
        if (!isset(self::CAPABILITIES[$action])) return array_merge($base, ['reason' => 'INVALID_ACTION']);
        if (!UuidCodec::isValid($id)) return array_merge($base, ['reason' => 'INVALID_PROPOSAL_ID']);
        if (!$this->validMutationSnapshot($action, $snapshot)) return array_merge($base, ['reason' => 'STALE_SNAPSHOT']);
        if (array_key_exists('proposal_id', $snapshot) && (!is_string($snapshot['proposal_id']) || $snapshot['proposal_id'] !== $id)) {
            return array_merge($base, ['reason' => 'STALE_SNAPSHOT']);
        }

        try {
            if (!(($this->can)(self::CAPABILITIES[$action]))) return array_merge($base, ['reason' => 'CAPABILITY_DENIED']);
            $proposal = $this->port->find($id);
            if ($proposal === null) return array_merge($base, ['reason' => 'PROPOSAL_NOT_FOUND']);
            $base['state'] = $proposal->state->value;
            if (!$this->matchesSnapshot($action, $proposal, $snapshot)) return array_merge($base, ['reason' => 'STALE_SNAPSHOT']);
            if (!$this->allows($action, $proposal->state)) return array_merge($base, [
                'outcome' => 'skipped',
                'reason' => 'INVALID_LIFECYCLE_ACTION',
            ]);

            if ($action === 'apply') {
                $eligibility = $this->port->eligibility($id);
                if ($this->videoReconciliation !== null && $proposal->entityType === 'video' && $proposal->operation === 'ingest' && $proposal->state === ProposalState::APPROVED && !$eligibility->ready && !$this->isIdempotentEligibility($eligibility)) {
                    $reconciled = $this->videoReconciliation->reconcile($id);
                    $success = in_array((string) ($reconciled['status'] ?? ''), ['APPLIED', 'REBUILT_AND_APPLIED', 'REUSED_CANONICAL', 'SUPERSEDED'], true);
                    return ['ok' => $success, 'outcome' => $success ? 'success' : (($reconciled['status'] ?? '') === 'BLOCKED' ? 'skipped' : 'failed'), 'status' => $reconciled['status'] ?? ($success ? 'APPLIED' : 'FAILED'), 'proposal_id' => $id, 'action' => $action, 'state' => $success ? ProposalState::APPLIED->value : $proposal->state->value, 'reason' => $reconciled['reason'] ?? ($reconciled['blockers'][0] ?? null), 'result' => $reconciled];
                }
                if (!$eligibility->ready && !$this->isIdempotentEligibility($eligibility)) {
                    return array_merge($base, [
                        'outcome' => 'skipped',
                        'status' => 'BLOCKED',
                        'reason' => $this->reason($eligibility->reasons),
                    ]);
                }
                $result = $this->port->apply($id);
                return ['ok' => true, 'outcome' => 'success', 'status' => 'APPLIED', 'proposal_id' => $id, 'action' => $action, 'state' => ProposalState::APPLIED->value, 'reason' => null, 'result' => $result];
            }

            $result = match ($action) {
                'submit' => $this->port->submit($id),
                'approve' => $this->port->approve($id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, (string) (($this->actor)())),
                'reject' => $this->port->reject($id, (string) (($this->actor)())),
            };
            return ['ok' => true, 'outcome' => 'success', 'proposal_id' => $id, 'action' => $action, 'state' => $result->state->value, 'reason' => null];
        } catch (\Throwable $error) {
            return array_merge($base, ['status' => 'FAILED', 'reason' => $this->exceptionReason($error), 'message' => $this->exceptionMessage($error)]);
        }
    }

    /** @param array<int, mixed> $items @return array<string, mixed> */
    public function bulk(string $action, array $items): array
    {
        if ($items === []) {
            $empty = ['ok' => false, 'outcome' => 'failed', 'proposal_id' => '', 'action' => $action, 'state' => null, 'reason' => 'NO_SELECTION'];
            return ['selected' => 0, 'succeeded' => 0, 'skipped' => 0, 'failed' => 1, 'failures' => [$empty], 'skipped_items' => [], 'results' => [$empty]];
        }
        $results = [];
        $failures = [];
        $skipped = [];
        foreach ($items as $item) {
            $snapshot = is_array($item) ? $item : [];
            $id = is_array($item) ? (string) ($item['proposal_id'] ?? '') : '';
            $result = $this->execute($action, $id, $snapshot);
            $results[] = $result;
            if ($result['ok'] !== true) $failures[] = $result;
            if (($result['outcome'] ?? 'failed') === 'skipped') $skipped[] = $result;
        }
        $selected = count($items);
        return [
            'selected' => $selected,
            'succeeded' => count(array_filter($results, static fn (array $result): bool => ($result['outcome'] ?? 'failed') === 'success')),
            'skipped' => count($skipped),
            'failed' => count($failures) - count($skipped),
            'failures' => array_values(array_filter($failures, static fn (array $result): bool => ($result['outcome'] ?? 'failed') !== 'skipped')),
            'skipped_items' => $skipped,
            'results' => $results,
        ];
    }

    private function matchesSnapshot(string $action, Proposal $proposal, array $snapshot): bool
    {
        $checks = ['revision' => $proposal->revision, 'state' => $proposal->state->value];
        if (in_array($action, ['approve', 'apply'], true)) {
            $checks['content_fingerprint'] = $proposal->contentFingerprint;
            $checks['dependency_fingerprint'] = $proposal->dependencyFingerprint;
        }
        foreach ($checks as $key => $current) {
            if ($key === 'revision') {
                if (!is_int($snapshot[$key]) || $snapshot[$key] !== $current) return false;
            } elseif ((string) $snapshot[$key] !== (string) $current) return false;
        }
        return true;
    }

    private function validMutationSnapshot(string $action, array $snapshot): bool
    {
        foreach (['revision', 'state'] as $key) {
            if (!array_key_exists($key, $snapshot)) return false;
        }
        if (!is_int($snapshot['revision']) || $snapshot['revision'] < 1) return false;
        if (!is_string($snapshot['state']) || ProposalState::tryFrom($snapshot['state']) === null) return false;
        if (in_array($action, ['approve', 'apply'], true)) {
            foreach (['content_fingerprint', 'dependency_fingerprint'] as $key) {
                if (!array_key_exists($key, $snapshot) || !is_string($snapshot[$key]) || $snapshot[$key] === '' || strlen($snapshot[$key]) > 128 || preg_match('/[^[:print:]]/', $snapshot[$key]) === 1) return false;
            }
        }
        return true;
    }

    private function allows(string $action, ProposalState $state): bool
    {
        return match ($action) {
            'submit' => $state === ProposalState::DRAFT,
            'approve', 'reject' => $state === ProposalState::SUBMITTED,
            'apply' => $state === ProposalState::APPROVED,
            default => false,
        };
    }

    private function isIdempotentEligibility(EligibilityResult $eligibility): bool
    {
        return count($eligibility->reasons) === 1 && $eligibility->reasons[0] === 'ALREADY_APPLIED';
    }

    private function reason(array $reasons): string|array
    {
        $reasons = array_values($reasons);
        return count($reasons) === 1 ? (string) $reasons[0] : $reasons;
    }

    private function exceptionReason(\Throwable $error): string
    {
        return match (true) {
            $error instanceof ProposalNotFound => 'PROPOSAL_NOT_FOUND',
            $error instanceof GovernancePermissionDenied => 'CAPABILITY_DENIED',
            $error instanceof ProposalBindingConflict => 'STALE_BINDING',
            $error instanceof InvalidProposalTransition => 'INVALID_LIFECYCLE_ACTION',
            $error instanceof DependencyValidationException => $error->errorCode,
            default => $this->embeddedDomainCode($error) ?? 'OPERATION_FAILED',
        };
    }

    private function embeddedDomainCode(\Throwable $error): ?string
    {
        $numeric = (string) $error->getCode();
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $numeric) === 1) return $numeric;
        $message = trim($error->getMessage());
        if (preg_match('/(?:^|:)([A-Z][A-Z0-9_]{2,63})$/', $message, $match) === 1) return $match[1];
        return null;
    }

    private function exceptionMessage(\Throwable $error): string
    {
        if ($error instanceof ProposalNotFound || $error instanceof GovernancePermissionDenied || $error instanceof ProposalBindingConflict || $error instanceof InvalidProposalTransition) {
            $message = preg_replace('/\s+/', ' ', trim($error->getMessage())) ?: 'Governance action failed.';
            return substr($message, 0, 160);
        }
        return 'Governance action failed.';
    }
}
