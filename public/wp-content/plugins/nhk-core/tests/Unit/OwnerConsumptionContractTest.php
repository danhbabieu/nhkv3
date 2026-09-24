<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Consumption\{OwnerCapability, OwnerCapabilityRegistry, OwnerConsumptionPlan};
use PHPUnit\Framework\TestCase;

final class OwnerConsumptionContractTest extends TestCase
{
    public function test_capabilities_declare_surfaces_without_forcing_seo_or_composer(): void
    {
        $registry = new OwnerCapabilityRegistry();
        $media = OwnerCapability::forOwner('media_image', ['caption', 'alt_text', 'description'], true, true);
        $future = OwnerCapability::forOwner('future_owner', ['summary'], true, true);
        $registry->register($media);
        $registry->register($future);

        self::assertSame(['caption', 'alt_text', 'description'], $registry->get('media_image')?->surfaces);
        self::assertFalse($registry->get('future_owner')?->supports('seo'));
        self::assertTrue($registry->get('future_owner')?->canonicalReadback);
    }

    public function test_consumption_plan_preserves_policy_trace_coverage_gaps_and_quality(): void
    {
        $plan = OwnerConsumptionPlan::forCapability(
            capability: OwnerCapability::forOwner('future_owner', ['summary'], true, true),
            owner: ['id' => 'owner-1', 'type' => 'future_owner'],
            surfaces: ['summary' => 'A supported summary'],
            dependencies: ['summary' => ['claim-1']],
            policies: ['summary' => ['claim-1' => 4]],
            trace: ['summary' => ['scope' => 'exact', 'specificity' => 'exact', 'treatment' => 'DIRECT_FACT', 'public_safe' => true]],
            coverage: ['claim-1' => ['claim_id' => 'claim-1', 'claim_revision' => 4]],
            gaps: ['summary' => 'NO_COMPOSER_REQUIRED'],
        );

        self::assertSame('future_owner', $plan->toArray()['owner_type']);
        self::assertSame('A supported summary', $plan->toArray()['surfaces']['summary']);
        self::assertSame(['claim-1'], $plan->toArray()['dependencies']['summary']);
        self::assertSame('NO_COMPOSER_REQUIRED', $plan->toArray()['gaps']['summary']);
        self::assertSame('exact', $plan->toArray()['trace']['summary']['specificity']);
    }
}
