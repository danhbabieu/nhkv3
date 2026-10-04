<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryRelatedTermProjection;
use NHK\Core\Domain\Dictionary\LexicalEntry;
use PHPUnit\Framework\TestCase;

final class DictionaryRelatedTermProjectionTest extends TestCase
{
    public function test_bounded_graph_owner_candidates_extend_same_owner_terms_deterministically(): void
    {
        $current = new LexicalEntry('entry-a', '400 ngày', '400 ngày', 'APPROVED', 'vi-VN', ['public_slug' => '400-ngay'], 1, []);
        $related = new LexicalEntry('entry-b', 'Anniversary clock', 'anniversary clock', 'APPROVED', 'en', ['public_slug' => 'anniversary-clock'], 1, []);
        $entries = new class($current, $related) {
            public function __construct(private LexicalEntry $current, private LexicalEntry $related) {}
            public function findEntriesBySemanticReference(string $type, string $id, int $limit): array { return $type === 'classification' && $id === 'owner-b' ? [$this->related] : []; }
        };
        $graph = static fn (string $type, string $id): array => [['id' => 'owner-b', 'type' => 'classification', 'origin' => ['kind' => 'DERIVED', 'hop_count' => 2]]];

        $items = (new DictionaryRelatedTermProjection($entries, $graph))->forReference('entry-a', 'classification', 'owner-a', 12);

        self::assertSame([['entry_id' => 'entry-b', 'title' => 'Anniversary clock', 'url' => '/tu-dien/anniversary-clock/', 'origin' => ['kind' => 'DERIVED', 'hop_count' => 2]]], $items);
    }
}
