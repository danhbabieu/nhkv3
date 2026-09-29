<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryRuntime;
use NHK\Core\Application\Mcp\McpDictionaryHandler;
use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryMention};
use NHK\Core\Infrastructure\Dictionary\WpdbDictionaryMentionRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class DictionaryCandidateProvenanceTest extends TestCase
{
    public function test_candidate_get_projects_bounded_mentions_with_pagination_without_mutation(): void
    {
        $candidate = new DictionaryCandidate('candidate-1', 'bộ thoát', hash('sha256', '{}'), ['Bộ thoát']);
        $mentions = [];
        for ($i = 1; $i <= 3; $i++) {
            $mentions[] = new DictionaryMention('mention-' . $i, hash('sha256', 'mention-' . $i), 'ARTICLE', (string) $i, 'bộ thoát', $candidate->contextHash, null, ['domain' => 'clock', 'raw_content' => 'private body'], $i === 1 ? 'STRONG' : 'NORMAL', '2026-09-29 00:00:00');
        }

        $runtime = (new \ReflectionClass(DictionaryRuntime::class))->newInstanceWithoutConstructor();
        $handler = new McpDictionaryHandler($runtime);
        $projection = new \ReflectionMethod($handler, 'provenance');
        $projection->setAccessible(true);
        $result = $projection->invoke($handler, array_slice($mentions, 0, 2), 2, 6);
        self::assertSame(2, count($result['items']));
        self::assertSame(2, $result['mention_count']);
        self::assertSame(6, $result['next_offset']);
        self::assertSame('ARTICLE', $result['items'][0]['source_kind']);
        self::assertSame('1', $result['items'][0]['source_id']);
        self::assertSame('clock', $result['items'][0]['context']['domain']);
        self::assertArrayNotHasKey('raw_content', $result['items'][0]['context']);
    }

    public function test_historical_zero_uuid_mention_is_nullable_and_schema_allows_null(): void
    {
        $database = new class { public string $prefix = 'wp_'; };
        $repository = new WpdbDictionaryMentionRepository($database);
        $method = new \ReflectionMethod($repository, 'hydrate');
        $mention = $method->invoke($repository, [
            'mention_uuid' => UuidCodec::toBinary('018f2f9a-0000-7000-8000-000000000001'),
            'fingerprint' => hash('sha256', 'mention'),
            'source_kind' => 'ARTICLE', 'source_id' => '1', 'normalized_term' => 'bộ thoát',
            'context_hash' => hash('sha256', '{}'),
            'concept_uuid' => UuidCodec::toBinary('00000000-0000-0000-0000-000000000000'),
            'context_json' => '{"domain":"clock"}', 'strength' => 'NORMAL', 'created_at' => '2026-09-29 00:00:00',
        ]);

        self::assertInstanceOf(DictionaryMention::class, $mention);
        self::assertNull($mention->conceptId);
        $migration = file_get_contents(__DIR__ . '/../../src/Infrastructure/Migration/DictionaryMigration015.php');
        self::assertIsString($migration);
        self::assertStringContainsString('concept_uuid BINARY(16) NULL', $migration);
    }
}
