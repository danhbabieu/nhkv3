<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

use NHK\Core\Application\Dictionary\DictionaryRuntime;
use NHK\Core\Application\Mcp\McpDictionaryHandler;
use NHK\Core\Domain\Dictionary\DictionaryCandidateState;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class McpDictionaryCandidateListTest extends TestCase
{
    public function test_candidate_list_exposes_backward_compatible_items_and_cursor_metadata(): void
    {
        $runtime = new DictionaryRuntime(new McpCandidateDatabase($this->rows(3)));
        $handler = new McpDictionaryHandler($runtime);

        $first = $handler->candidateList(null, 2);
        $second = $handler->candidateList(null, 2, $first['next_cursor']);

        self::assertSame('available', $first['status']);
        self::assertSame(2, $first['count']);
        self::assertSame(2, $first['page_count']);
        self::assertSame(3, $first['total']);
        self::assertTrue($first['has_more']);
        self::assertNotNull($first['next_cursor']);
        self::assertSame(1, $second['count']);
        self::assertFalse($second['has_more']);
        self::assertSame('01a00000-0000-7000-8000-000000000003', $second['items'][0]['id']);
    }

    public function test_candidate_list_rejects_cursor_reused_with_another_state_filter(): void
    {
        $runtime = new DictionaryRuntime(new McpCandidateDatabase($this->rows(3)));
        $handler = new McpDictionaryHandler($runtime);
        $first = $handler->candidateList(null, 1);

        $this->expectExceptionMessage('DICTIONARY_CANDIDATE_CURSOR_FILTER_MISMATCH');
        $handler->candidateList(DictionaryCandidateState::AMBIGUOUS, 1, $first['next_cursor']);
    }

    /** @return list<array<string,mixed>> */
    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $id = sprintf('01a00000-0000-7000-8000-%012d', $i);
            $rows[] = [
                'candidate_uuid' => UuidCodec::toBinary($id),
                'normalized_term' => 'term-' . $i,
                'context_hash' => hash('sha256', '{}'),
                'raw_forms_json' => json_encode(['Term ' . $i]),
                'candidate_state' => DictionaryCandidateState::NEEDS_REVIEW,
                'context_json' => '{}',
                'suggestions_json' => '[]',
                'occurrences' => 1,
                'first_seen_at' => '2026-10-08 00:00:00.000000',
                'last_seen_at' => '2026-10-08 00:00:00.000000',
                'revision' => 1,
            ];
        }
        return $rows;
    }
}

final class McpCandidateDatabase
{
    public string $prefix = 'wp_';
    private ?string $table = null;
    private ?string $after = null;
    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows) {}
    public function prepare(string $query, mixed ...$args): string
    {
        $this->table = null;
        $this->after = null;
        if (str_contains($query, 'SHOW TABLES LIKE')) $this->table = (string) ($args[0] ?? '');
        foreach ($args as $arg) if (is_string($arg) && strlen($arg) === 16) {
            try { $this->after = UuidCodec::fromBinary($arg); } catch (\Throwable) {}
        }
        return $query;
    }
    public function get_var(string $query): mixed
    {
        if (str_contains($query, 'SHOW TABLES LIKE')) return $this->table;
        return count($this->eligibleRows());
    }
    public function get_results(string $query, mixed $output = null): array { return $this->eligibleRows(); }
    /** @return list<array<string,mixed>> */
    private function eligibleRows(): array
    {
        $rows = $this->rows;
        if ($this->after !== null) $rows = array_values(array_filter($rows, fn (array $row): bool => UuidCodec::fromBinary($row['candidate_uuid']) > $this->after));
        return $rows;
    }
}
