<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\{ControlledApplyOperationRegistry, GovernedOperationPolicyRegistry, OperationScopedStagingGuard, ProductionGovernanceAdmission};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class GovernanceOperationPolicyContractTest extends TestCase
{
    public function test_every_policy_entry_is_executor_supported_and_unknown_pairs_fail_closed(): void
    {
        $registry = new GovernedOperationPolicyRegistry();
        $executor = new ControlledApplyOperationRegistry();

        foreach ($registry->all() as $policy) self::assertTrue($executor->supports($policy->entityType, $policy->operation));
        self::assertFalse($executor->supports('video', 'not-registered'));
        self::assertFalse($executor->supports('unknown-owner', 'update'));
    }

    public function test_staging_denied_policy_cannot_reach_scoped_guard(): void
    {
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal(
            UuidCodec::newV7(), $videoId, 'retire', ['staging_acceptance' => ['approved' => true]],
            'content', 3, 'dependency', ProposalState::APPROVED,
            idempotencyKey: 'video-retire-denied', targetUuid: $videoId, entityType: 'video'
        );

        $guard = new OperationScopedStagingGuard(
            static fn (): string => 'staging',
            static fn (): bool => true,
            scopeVerifier: static fn (): bool => true,
        );

        $this->expectExceptionMessage('STAGING_OPERATION_UNREGISTERED');
        $guard->assertAllowed($proposal);
    }

    public function test_production_capabilities_are_read_from_policy_without_staging_scope(): void
    {
        $videoId = UuidCodec::newV7();
        $proposal = new Proposal(
            UuidCodec::newV7(), $videoId, 'source_refresh', ['source_snapshot' => []],
            'content', 3, 'dependency', ProposalState::APPROVED,
            idempotencyKey: 'video-source-refresh-policy', targetUuid: $videoId, entityType: 'video'
        );
        $seen = [];
        $admission = new ProductionGovernanceAdmission(
            static fn (): string => 'production',
            static function (string $capability) use (&$seen): bool { $seen[] = $capability; return true; },
        );

        $admission->assertAllowed($proposal);

        self::assertContains('nhk_apply_proposals', $seen);
        self::assertContains('nhk_create_proposals', $seen);
    }
}
