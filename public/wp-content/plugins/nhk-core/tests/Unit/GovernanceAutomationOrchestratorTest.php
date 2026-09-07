<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Governance\GovernedSemanticIngestOrchestrator;
use NHK\Core\Contracts\Governance\{AutomationPolicyStorage, GovernedLifecycle};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class GovernanceAutomationOrchestratorTest extends TestCase
{
    public function test_auto_approve_stops_after_eligibility_without_apply(): void
    {
        $proposal = $this->proposal('video-auto-approve');
        $governance = $this->governance($proposal);
        $resolver = $this->resolver(['video' => 'AUTO_APPROVE']);
        $runner = new GovernedSemanticIngestOrchestrator($governance, static fn (array $review): bool => true, static function (): array { throw new \LogicException('apply must not run'); }, $resolver);

        $result = $runner->run([['operation' => 'ingest', 'entity_type' => 'video', 'payload' => []]])[0];

        self::assertSame('ready_to_apply', $result['status']);
        self::assertSame('eligibility', $result['gate_reached']);
        self::assertSame('approved', $result['proposal_state']);
    }

    public function test_auto_publish_requires_projection_and_returns_frontend_status(): void
    {
        $proposal = $this->proposal('video-auto-publish');
        $governance = $this->governance($proposal);
        $resolver = $this->resolver(['video' => 'AUTO_PUBLISH']);
        $runner = new GovernedSemanticIngestOrchestrator(
            $governance,
            static fn (array $review): bool => true,
            static fn (string $id): array => ['canonical_readback' => ['canonical_id' => UuidCodec::newV7()]],
            $resolver,
            static fn (array $applied): array => ['frontend_available' => true, 'canonical_readback' => $applied['canonical_readback']],
        );

        $result = $runner->run([['operation' => 'ingest', 'entity_type' => 'video', 'payload' => []]])[0];

        self::assertSame('published', $result['status']);
        self::assertSame('frontend', $result['gate_reached']);
        self::assertTrue($result['frontend']['frontend_available']);
    }

    public function test_auto_publish_apply_failure_returns_blocker_without_success(): void
    {
        $proposal = $this->proposal('video-blocked');
        $governance = $this->governance($proposal);
        $runner = new GovernedSemanticIngestOrchestrator(
            $governance,
            static fn (array $review): bool => true,
            static function (): array { throw new \RuntimeException('APPLY_FAILED'); },
            $this->resolver(['video' => 'AUTO_PUBLISH']),
        );

        $result = $runner->run([['operation' => 'ingest', 'entity_type' => 'video', 'payload' => []]])[0];

        self::assertSame('blocked', $result['status']);
        self::assertSame('apply', $result['gate_reached']);
        self::assertSame(['APPLY_FAILED'], $result['blockers']);
    }

    private function resolver(array $policies): GovernanceAutomationPolicyResolver
    {
        return new GovernanceAutomationPolicyResolver(['video'], new class($policies) implements AutomationPolicyStorage {
            public function __construct(private array $policies) {}
            public function read(): array { return $this->policies; }
            public function write(array $policies): void { $this->policies = $policies; }
        });
    }

    private function proposal(string $key): Proposal
    {
        return new Proposal(UuidCodec::newV7(), 'video', 'ingest', [], 'content', null, 'dependency', ProposalState::DRAFT, idempotencyKey: $key, entityType: 'video');
    }

    private function governance(Proposal $proposal): GovernedLifecycle
    {
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->method('createFromArguments')->willReturn($proposal);
        $governance->method('submit')->willReturn($proposal);
        $governance->method('review')->willReturn(['state' => 'submitted', 'content_fingerprint' => 'content', 'dependency_fingerprint' => 'dependency']);
        $governance->method('approve')->willReturn($proposal->transition(ProposalState::APPROVED, '0'));
        $governance->method('eligibility')->willReturn(['ready' => true, 'reasons' => []]);
        return $governance;
    }
}
