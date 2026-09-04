<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Seo\SeoRuntimeReadback;
use PHPUnit\Framework\TestCase;

final class SeoRuntimeReadbackTest extends TestCase
{
    protected function setUp(): void { self::assertTrue(class_exists(SeoRuntimeReadback::class), 'SEO runtime read-back is not implemented.'); }

    public function test_readback_returns_field_level_pass_without_claiming_search_engine_state(): void
    {
        $result = (new SeoRuntimeReadback())->verify(['url' => '/odo/', 'canonical' => '/odo/', 'robots' => 'index'], static fn (): array => ['url' => '/odo/', 'canonical' => '/odo/', 'robots' => 'index']);
        self::assertSame('PASS', $result->status());
        self::assertSame([], $result->mismatches());
    }

    public function test_unavailable_runtime_is_distinct_from_empty_success(): void
    {
        $result = (new SeoRuntimeReadback())->verify(['url' => '/odo/'], static fn (): array => throw new \RuntimeException('connection refused'));
        self::assertSame('ENVIRONMENT_BLOCKED', $result->status());
        self::assertNotSame('PASS', $result->status());
    }
}
