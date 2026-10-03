<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryEnrichmentCoverage;
use PHPUnit\Framework\TestCase;

final class DictionaryEnrichmentCoverageTest extends TestCase
{
    public function test_coverage_keeps_available_empty_and_unavailable_distinct_and_counts_mentions(): void
    {
        $coverage = new DictionaryEnrichmentCoverage([
            'knowledge' => static fn (): array => ['status' => 'EMPTY', 'count' => 0],
            'media' => static fn (): array => ['status' => 'UNAVAILABLE', 'count' => 0],
            'video' => static fn (): array => ['status' => 'AVAILABLE', 'count' => 2, 'items' => [['id' => 'v1']]],
            'mentions' => static fn (): array => ['status' => 'AVAILABLE', 'count' => 3, 'by_kind' => ['ARTICLE' => 2, 'KNOWLEDGE' => 1]],
        ]);

        $result = $coverage->forReference('classification', 'owner-1', ['sense_id' => 'sense-1']);

        self::assertSame('EMPTY', $result['knowledge']['status']);
        self::assertSame('UNAVAILABLE', $result['media']['status']);
        self::assertSame(2, $result['video']['count']);
        self::assertSame(2, $result['mentions']['by_kind']['ARTICLE']);
        self::assertSame('UNAVAILABLE', $result['articles']['status']);
        self::assertSame('UNAVAILABLE', $result['related_terms']['status']);
    }

    public function test_blocked_and_ambiguous_owner_states_are_reported_without_projection_calls(): void
    {
        $calls = 0;
        $coverage = new DictionaryEnrichmentCoverage([
            'owner_state' => static fn (): array => ['status' => 'AMBIGUOUS', 'count' => 0],
            'knowledge' => static function () use (&$calls): array { $calls++; return ['status' => 'AVAILABLE', 'count' => 9]; },
        ]);

        $result = $coverage->forReference('', '', ['owner_state' => 'AMBIGUOUS']);

        self::assertSame('AMBIGUOUS', $result['status']);
        self::assertSame(0, $calls);
        self::assertSame('AMBIGUOUS', $result['knowledge']['status']);
    }
}
