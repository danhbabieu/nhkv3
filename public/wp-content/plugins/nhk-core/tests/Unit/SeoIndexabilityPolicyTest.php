<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Seo\SeoIndexabilityPolicy;
use PHPUnit\Framework\TestCase;

final class SeoIndexabilityPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertTrue(class_exists(SeoIndexabilityPolicy::class), 'Shared SEO indexability policy is not implemented.');
    }

    public function test_indexability_requires_ready_public_canonical_projection(): void
    {
        $policy = new SeoIndexabilityPolicy();
        self::assertTrue($policy->evaluate(['readiness' => 'READY', 'public_eligible' => true, 'canonical_url' => '/odo/'])->indexable());
        self::assertFalse($policy->evaluate(['readiness' => 'INCOMPLETE', 'public_eligible' => true, 'canonical_url' => '/odo/'])->indexable());
    }

    public function test_indexability_preserves_unavailable_and_canonical_mismatch_reasons(): void
    {
        $policy = new SeoIndexabilityPolicy();
        $unavailable = $policy->evaluate(['readiness' => 'UNAVAILABLE', 'public_eligible' => false, 'canonical_url' => null]);
        self::assertFalse($unavailable->indexable());
        self::assertSame(['RUNTIME_UNAVAILABLE'], $unavailable->reasons());

        $mismatch = $policy->evaluate(['readiness' => 'READY', 'public_eligible' => true, 'canonical_url' => '/a/', 'rendered_url' => '/b/']);
        self::assertFalse($mismatch->indexable());
        self::assertSame(['CANONICAL_URL_MISMATCH'], $mismatch->reasons());
    }
}
