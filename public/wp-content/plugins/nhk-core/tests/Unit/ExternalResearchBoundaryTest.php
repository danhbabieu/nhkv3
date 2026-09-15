<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Contracts\Article\ExternalResearchProvider;
use NHK\Core\Domain\Article\ExternalResearchResult;
use PHPUnit\Framework\TestCase;

final class ExternalResearchBoundaryTest extends TestCase
{
    public function test_external_research_is_a_bounded_read_only_port_and_unavailable_is_explicit(): void
    {
        $provider = new class implements ExternalResearchProvider {
            public function research(string $topic, array $context = [], int $maxItems = 10): ExternalResearchResult
            {
                return ExternalResearchResult::unavailable();
            }
        };
        $result = $provider->research('đồng hồ', ['read_only' => true], 3);

        self::assertSame('unavailable', $result->status);
        self::assertSame([], $result->items);
        self::assertContains('EXTERNAL_RESEARCH_RUNTIME_UNAVAILABLE', $result->warnings);
    }
}
