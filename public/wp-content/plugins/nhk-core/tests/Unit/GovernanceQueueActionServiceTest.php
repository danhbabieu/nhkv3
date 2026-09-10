<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Contracts\Governance\GovernanceActionPort;
use NHK\Core\Domain\Governance\{EligibilityResult, Proposal, ProposalState};
use NHK\Core\Infrastructure\Admin\GovernanceQueueActionService;
use PHPUnit\Framework\TestCase;

final class GovernanceQueueActionServiceTest extends TestCase
{
    private const FIRST = '018f2f1e-7b2c-7abc-8def-0123456789ab';
    private const SECOND = '018f2f1e-7b2c-7abc-8def-0123456789ac';

    private RecordingGovernanceActionPort $port;
    private GovernanceQueueActionService $service;
    /** @var list<string> */
    private array $seenCapabilities = [];

    protected function setUp(): void
    {
        $this->port = new RecordingGovernanceActionPort([
            self::FIRST => $this->proposal(self::FIRST, ProposalState::SUBMITTED),
            self::SECOND => $this->proposal(self::SECOND, ProposalState::SUBMITTED),
        ]);
        $this->service = new GovernanceQueueActionService($this->port, function (string $capability): bool { $this->seenCapabilities[] = $capability; return true; }, static fn (): string => '7');
    }

    public function test_bulk_approve_continues_after_one_missing_item(): void
    {
        $result = $this->service->bulk('approve', [
            $this->snapshot(self::FIRST),
            ['proposal_id' => '00000000-0000-4000-8000-000000000001', 'revision' => 1, 'state' => 'submitted', 'content_fingerprint' => 'unknown', 'dependency_fingerprint' => 'unknown'],
            $this->snapshot(self::SECOND, ProposalState::SUBMITTED),
        ]);

        self::assertSame(3, $result['selected']);
        self::assertSame(2, $result['succeeded']);
        self::assertSame(1, $result['failed']);
        self::assertSame('00000000-0000-4000-8000-000000000001', $result['failures'][0]['proposal_id']);
        self::assertSame('PROPOSAL_NOT_FOUND', $result['failures'][0]['reason']);
    }

    public function test_apply_delegates_eligibility_and_preserves_partial_failure(): void
    {
        $this->port->eligibility[self::FIRST] = EligibilityResult::blocked('NOT_APPROVED');
        $this->port->proposals[self::FIRST] = $this->proposal(self::FIRST, ProposalState::APPROVED);
        $this->port->proposals[self::SECOND] = $this->proposal(self::SECOND, ProposalState::APPROVED);

        $result = $this->service->bulk('apply', [
            $this->snapshot(self::SECOND, ProposalState::APPROVED),
            $this->snapshot(self::FIRST, ProposalState::APPROVED),
        ]);

        self::assertSame(1, $result['succeeded']);
        self::assertSame(1, $result['failed']);
        self::assertSame(self::FIRST, $result['failures'][0]['proposal_id']);
        self::assertSame('NOT_APPROVED', $result['failures'][0]['reason']);
        self::assertSame(['eligibility', 'apply'], $this->port->callsFor(self::SECOND));
        self::assertSame(['eligibility'], $this->port->callsFor(self::FIRST));
    }

    public function test_apply_returns_all_canonical_eligibility_reason_codes_without_applying(): void
    {
        $this->port->eligibility[self::SECOND] = EligibilityResult::blocked('TARGET_NOT_FOUND', 'DEPENDENCY_NOT_APPLIED');
        $this->port->proposals[self::SECOND] = $this->proposal(self::SECOND, ProposalState::APPROVED);

        $result = $this->service->execute('apply', self::SECOND, $this->snapshot(self::SECOND, ProposalState::APPROVED));

        self::assertFalse($result['ok']);
        self::assertSame(['TARGET_NOT_FOUND', 'DEPENDENCY_NOT_APPLIED'], $result['reason']);
        self::assertSame(['eligibility'], $this->port->callsFor(self::SECOND));
    }

    public function test_single_action_returns_canonical_state_and_apply_result(): void
    {
        $this->port->proposals[self::SECOND] = $this->proposal(self::SECOND, ProposalState::APPROVED);
        $result = $this->service->execute('apply', self::SECOND, $this->snapshot(self::SECOND, ProposalState::APPROVED));

        self::assertTrue($result['ok']);
        self::assertSame(self::SECOND, $result['proposal_id']);
        self::assertSame('apply', $result['action']);
        self::assertSame('applied', $result['state']);
        self::assertSame(['proposal_id' => self::SECOND, 'idempotent' => false], $result['result']);
    }

    public function test_repeated_apply_preserves_canonical_idempotent_result(): void
    {
        $this->port->proposals[self::SECOND] = $this->proposal(self::SECOND, ProposalState::APPLIED);
        $this->port->eligibility[self::SECOND] = EligibilityResult::blocked('ALREADY_APPLIED');

        $result = $this->service->execute('apply', self::SECOND, $this->snapshot(self::SECOND, ProposalState::APPLIED));

        self::assertTrue($result['ok']);
        self::assertTrue($result['result']['idempotent']);
        self::assertSame(['eligibility', 'apply'], $this->port->callsFor(self::SECOND));
    }

    public function test_stale_revision_fingerprint_and_state_fail_closed(): void
    {
        foreach ([
            ['revision' => 99],
            ['content_fingerprint' => 'changed'],
            ['dependency_fingerprint' => 'changed'],
            ['state' => 'draft'],
        ] as $stale) {
            $result = $this->service->execute('approve', self::FIRST, array_merge($this->snapshot(self::FIRST), $stale));
            self::assertFalse($result['ok']);
            self::assertSame('STALE_SNAPSHOT', $result['reason']);
        }

        self::assertSame([], $this->port->callsFor(self::FIRST));
    }

    public function test_mutation_requires_all_snapshot_fields_before_read_or_write(): void
    {
        foreach (['revision', 'state', 'content_fingerprint', 'dependency_fingerprint'] as $missing) {
            $snapshot = $this->snapshot(self::FIRST);
            unset($snapshot[$missing]);
            $result = $this->service->execute('approve', self::FIRST, $snapshot);
            self::assertFalse($result['ok']);
            self::assertSame('STALE_SNAPSHOT', $result['reason']);
        }

        self::assertSame([], $this->port->callsFor(self::FIRST));
    }

    public function test_malformed_snapshot_fields_fail_closed_without_mutation(): void
    {
        foreach ([
            ['revision' => '1'],
            ['revision' => 0],
            ['state' => 'not-a-proposal-state'],
            ['content_fingerprint' => ''],
            ['dependency_fingerprint' => 123],
        ] as $malformed) {
            $result = $this->service->execute('approve', self::FIRST, array_merge($this->snapshot(self::FIRST), $malformed));
            self::assertFalse($result['ok']);
            self::assertSame('STALE_SNAPSHOT', $result['reason']);
        }

        self::assertSame([], $this->port->callsFor(self::FIRST));
    }

    public function test_exception_diagnostic_is_sanitized_and_bounded(): void
    {
        $this->port->throwOn['approve'] = new \RuntimeException("secret\n" . str_repeat('x', 400));
        $result = $this->service->execute('approve', self::FIRST, $this->snapshot(self::FIRST));

        self::assertFalse($result['ok']);
        self::assertSame('OPERATION_FAILED', $result['reason']);
        self::assertArrayHasKey('message', $result);
        self::assertLessThanOrEqual(160, strlen($result['message']));
        self::assertStringNotContainsString("\n", $result['message']);
        self::assertStringNotContainsString('secret', $result['message']);
    }

    public function test_invalid_action_identifier_missing_capability_and_lifecycle_fail_closed(): void
    {
        self::assertSame('INVALID_ACTION', $this->service->execute('publish', self::FIRST)['reason']);
        self::assertSame('INVALID_PROPOSAL_ID', $this->service->execute('approve', 'bad-id')['reason']);
        self::assertSame('INVALID_LIFECYCLE_ACTION', $this->service->execute('apply', self::FIRST, $this->snapshot(self::FIRST))['reason']);

        $denied = new GovernanceQueueActionService($this->port, static fn (): bool => false, static fn (): string => '7');
        self::assertSame('CAPABILITY_DENIED', $denied->execute('approve', self::FIRST, $this->snapshot(self::FIRST))['reason']);
        self::assertSame([], $this->port->callsFor(self::FIRST));
    }

    public function test_approve_reject_and_submit_use_expected_capability_and_actor(): void
    {
        $this->port->proposals[self::FIRST] = $this->proposal(self::FIRST, ProposalState::DRAFT);
        $this->service->execute('submit', self::FIRST, $this->snapshot(self::FIRST, ProposalState::DRAFT));
        $this->port->proposals[self::FIRST] = $this->proposal(self::FIRST, ProposalState::SUBMITTED);
        $this->service->execute('reject', self::FIRST, $this->snapshot(self::FIRST, ProposalState::SUBMITTED));
        $this->port->proposals[self::FIRST] = $this->proposal(self::FIRST, ProposalState::SUBMITTED);
        $this->service->execute('approve', self::FIRST, $this->snapshot(self::FIRST, ProposalState::SUBMITTED));

        self::assertSame(['submit', 'reject', 'approve'], $this->port->callsFor(self::FIRST));
        self::assertSame(['nhk_submit_proposals', 'nhk_approve_proposals', 'nhk_approve_proposals'], $this->seenCapabilities);
        self::assertSame('7', $this->port->actors[1]);
    }

    /** @return array<string, mixed> */
    private function snapshot(string $id, ProposalState $state = ProposalState::SUBMITTED): array
    {
        $proposal = $this->port->proposals[$id];
        return [
            'proposal_id' => $id,
            'revision' => $proposal->revision,
            'content_fingerprint' => $proposal->contentFingerprint,
            'dependency_fingerprint' => $proposal->dependencyFingerprint,
            'state' => $state->value,
        ];
    }

    private function proposal(string $id, ProposalState $state): Proposal
    {
        return new Proposal($id, 'subject-' . $id, 'create', [], 'content-' . $id, 1, 'dependency-' . $id, state: $state, idempotencyKey: 'key-' . $id);
    }
}

final class RecordingGovernanceActionPort implements GovernanceActionPort
{
    /** @param array<string, Proposal> $proposals */
    public function __construct(public array $proposals) {}

    /** @var array<string, EligibilityResult> */
    public array $eligibility = [];
    /** @var list<string> */
    public array $capabilities = [];
    /** @var list<string> */
    public array $actors = [];
    /** @var list<array{action:string,id:string}> */
    public array $calls = [];
    /** @var array<string, \Throwable> */
    public array $throwOn = [];

    public function find(string $id): ?Proposal { $this->calls[] = ['action' => 'find', 'id' => $id]; return $this->proposals[$id] ?? null; }
    public function submit(string $id): Proposal { $this->calls[] = ['action' => 'submit', 'id' => $id]; return $this->proposals[$id]; }
    public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { if (isset($this->throwOn['approve'])) throw $this->throwOn['approve']; $this->calls[] = ['action' => 'approve', 'id' => $id]; $this->actors[] = $actor; return $this->proposals[$id]->transition(ProposalState::APPROVED, $actor); }
    public function reject(string $id, string $actor): Proposal { $this->calls[] = ['action' => 'reject', 'id' => $id]; $this->actors[] = $actor; return $this->proposals[$id]->transition(ProposalState::REJECTED, $actor); }
    public function eligibility(string $id): EligibilityResult { $this->calls[] = ['action' => 'eligibility', 'id' => $id]; return $this->eligibility[$id] ?? EligibilityResult::ready(); }
    public function apply(string $id): array { $this->calls[] = ['action' => 'apply', 'id' => $id]; return ['proposal_id' => $id, 'idempotent' => $this->proposals[$id]->state === ProposalState::APPLIED]; }

    /** @return list<string> */
    public function callsFor(string $id): array { return array_column(array_values(array_filter($this->calls, static fn (array $call): bool => $call['id'] === $id && $call['action'] !== 'find')), 'action'); }
}
