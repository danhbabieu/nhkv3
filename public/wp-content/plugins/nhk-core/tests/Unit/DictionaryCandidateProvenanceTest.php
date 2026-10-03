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

    public function test_mention_repository_reads_mentions_reverse_by_concept(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
        $conceptId = '018f2f9a-0000-7000-8000-000000000001';
        $database = new class($conceptId) {
            public string $prefix = 'wp_';
            public function __construct(private string $conceptId) {}
            public function prepare(string $query, mixed ...$args): string { return $query; }
            public function get_results(string $query, mixed $output): array { return [[
                'mention_uuid' => UuidCodec::toBinary('018f2f9a-0000-7000-8000-000000000002'),
                'fingerprint' => hash('sha256', 'mention'), 'source_kind' => 'ARTICLE', 'source_id' => '42',
                'normalized_term' => '400 ngày', 'context_hash' => hash('sha256', '{}'), 'concept_uuid' => UuidCodec::toBinary($this->conceptId),
                'context_json' => '{}', 'strength' => 'NORMAL', 'created_at' => '2026-10-03 00:00:00',
            ]]; }
        };

        $mentions = (new WpdbDictionaryMentionRepository($database))->listByConcept($conceptId, 10, 0);

        self::assertCount(1, $mentions);
        self::assertSame($conceptId, $mentions[0]->conceptId);
        self::assertSame('ARTICLE', $mentions[0]->sourceKind);
    }

    public function test_public_mention_projection_groups_only_resolved_public_sources(): void
    {
        $repository = new class {
            public function listByConcept(string $conceptId, int $limit, int $offset): array
            {
                return [
                    new DictionaryMention('018f2f9a-0000-7000-8000-000000000003', hash('sha256', 'article'), 'ARTICLE', '42', '400 ngày', hash('sha256', '{}')),
                    new DictionaryMention('018f2f9a-0000-7000-8000-000000000004', hash('sha256', 'private'), 'VIDEO', 'private-1', '400 ngày', hash('sha256', '{}')),
                ];
            }
        };

        $result = (new \NHK\Core\Application\Dictionary\DictionaryMentionPublicProjection($repository, static fn (string $kind, string $id): ?array => $kind === 'ARTICLE' ? ['title' => 'Bài viết', 'url' => '/bai-viet/'] : null))->forConcept('concept-1');

        self::assertSame('AVAILABLE_WITH_ITEMS', $result['status']);
        self::assertSame('Bài viết', $result['groups']['ARTICLE'][0]['title']);
        self::assertArrayNotHasKey('VIDEO', $result['groups']);
    }
}
