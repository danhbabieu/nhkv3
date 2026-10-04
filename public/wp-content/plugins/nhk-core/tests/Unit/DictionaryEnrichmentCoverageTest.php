<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryEnrichmentCoverage;
use PHPUnit\Framework\TestCase;

final class DictionaryEnrichmentCoverageTest extends TestCase
{
    public function test_knowledge_owner_packet_matches_bounded_public_dictionary_projection(): void
    {
        $packet = ['knowledge' => ['status' => 'AVAILABLE', 'facets' => [
            'movement' => [['id' => 'k1'], ['id' => 'k2']],
            'material' => [['id' => 'k3']],
            'origin' => [['id' => 'k4'], ['id' => 'k5']],
            'overflow' => [['id' => 'k6']],
        ]]];

        $coverage = DictionaryEnrichmentCoverage::knowledgeFromOwnerDossier($packet);

        self::assertSame('AVAILABLE_WITH_ITEMS', $coverage['status']);
        self::assertSame(6, $coverage['count']);
        self::assertSame(['k1', 'k2', 'k3', 'k4', 'k5', 'k6'], array_column($coverage['items'], 'id'));
        self::assertFalse($coverage['has_more']);
    }

    public function test_knowledge_owner_packet_preserves_empty_and_unavailable_states(): void
    {
        self::assertSame('AVAILABLE_EMPTY', DictionaryEnrichmentCoverage::knowledgeFromOwnerDossier([
            'knowledge' => ['status' => 'AVAILABLE', 'facets' => []],
        ])['status']);
        self::assertSame('UNAVAILABLE', DictionaryEnrichmentCoverage::knowledgeFromOwnerDossier([
            'knowledge' => ['status' => 'UNAVAILABLE', 'facets' => []],
        ])['status']);
    }

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
