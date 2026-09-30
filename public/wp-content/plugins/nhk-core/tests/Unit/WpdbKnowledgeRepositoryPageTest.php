<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

use NHK\Core\Infrastructure\Knowledge\WpdbKnowledgeRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class WpdbKnowledgeRepositoryPageTest extends TestCase
{
    public function test_page_reports_invalid_rows_and_advances_by_raw_stable_key(): void
    {
        $validId = '11111111-1111-4111-8111-111111111111';
        $db = new class($validId) {
            public string $prefix = 'wp_';
            private bool $first = true;
            public function __construct(private string $validId) {}
            public function prepare(string $sql, mixed ...$args): string { return $sql; }
            public function get_results(string $sql, mixed $output): array
            {
                if (!$this->first) return [];
                $this->first = false;
                return [
                    ['id' => 1, 'canonical_uuid' => UuidCodec::toBinary('22222222-2222-4222-8222-222222222222'), 'stable_key' => 'knowledge:broken', 'claim_text' => 'ignored', 'claim_type' => 'fact', 'provenance_json' => '{bad', 'state' => 1, 'revision' => 1],
                    ['id' => 2, 'canonical_uuid' => UuidCodec::toBinary($this->validId), 'stable_key' => 'knowledge:valid', 'claim_text' => 'Valid Term', 'claim_type' => 'fact', 'provenance_json' => '{}', 'state' => 1, 'revision' => 1],
                ];
            }
        };

        $page = (new WpdbKnowledgeRepository($db))->page(false, null, 1);

        self::assertSame(['knowledge:broken'], array_column($page['diagnostics'], 'source_id'));
        self::assertSame([], $page['items']);
        self::assertTrue($page['has_more']);
        self::assertSame('knowledge:broken', $page['next_cursor']);
    }
}
