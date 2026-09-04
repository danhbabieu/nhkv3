<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Seo\SeoReadinessPolicy;
use NHK\Core\Domain\Seo\SeoReadinessResult;
use PHPUnit\Framework\TestCase;

final class SeoReadinessPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        self::assertTrue(class_exists(SeoReadinessPolicy::class), 'Shared SEO readiness policy is not implemented.');
        self::assertTrue(class_exists(SeoReadinessResult::class), 'Shared SEO readiness result is not implemented.');
    }

    public function test_complete_projection_is_ready(): void
    {
        $result = (new SeoReadinessPolicy())->evaluate([
            'canonical_identity' => true,
            'public_identity' => true,
            'canonical_url' => '/thuong-hieu/odo/',
            'public_eligible' => true,
            'content_sufficient' => true,
            'compliance' => 'PASS',
        ]);

        self::assertSame(SeoReadinessResult::READY, $result->status());
        self::assertSame([], $result->reasons());
    }

    public function test_missing_identity_and_content_are_deterministic_incomplete_blockers(): void
    {
        $result = (new SeoReadinessPolicy())->evaluate([
            'canonical_identity' => true,
            'public_identity' => false,
            'canonical_url' => '',
            'public_eligible' => true,
            'content_sufficient' => false,
            'compliance' => 'PASS',
        ]);

        self::assertSame(SeoReadinessResult::INCOMPLETE, $result->status());
        self::assertSame(['MISSING_PUBLIC_IDENTITY', 'INSUFFICIENT_PUBLIC_CONTENT'], $result->reasons());
    }

    public function test_unavailable_runtime_is_not_empty_success(): void
    {
        $result = (new SeoReadinessPolicy())->evaluate(['runtime_available' => false]);

        self::assertSame(SeoReadinessResult::UNAVAILABLE, $result->status());
        self::assertSame(['RUNTIME_UNAVAILABLE'], $result->reasons());
    }

    public function test_structured_data_can_be_not_applicable_without_blocking_page_readiness(): void
    {
        $result = (new SeoReadinessPolicy())->evaluate([
            'canonical_identity' => true,
            'public_identity' => true,
            'canonical_url' => '/kho/',
            'public_eligible' => true,
            'content_sufficient' => true,
            'compliance' => 'PASS',
            'structured_data_applicable' => false,
        ]);

        self::assertSame(SeoReadinessResult::READY, $result->status());
        self::assertTrue($result->structuredDataNotApplicable());
        self::assertSame(['STRUCTURED_DATA_INAPPLICABLE'], $result->structuredDataReasons());
    }
}
