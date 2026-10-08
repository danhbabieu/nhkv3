<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

use NHK\Core\Domain\Dictionary\DictionaryCandidateState;
use NHK\Core\Infrastructure\Dictionary\WpdbDictionaryCandidateRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WpdbDictionaryCandidateRepositoryTest extends TestCase
{
    public function test_review_page_walks_101_equal_occurrence_candidates_without_duplicates(): void
    {
        $database = new CandidatePageDatabase($this->rows(101));
        $repository = new WpdbDictionaryCandidateRepository($database);

        $first = $repository->pageForReview(100);
        $second = $repository->pageForReview(100, $first['next_cursor']);

        self::assertSame(101, $first['total']);
        self::assertTrue($first['has_more']);
        self::assertCount(100, $first['items']);
        self::assertFalse($second['has_more']);
        self::assertSame(101, $second['total']);
        self::assertCount(1, $second['items']);
        self::assertSame(
            array_merge(array_slice($database->ids, 0, 100), [end($database->ids)]),
            array_merge(array_map(static fn ($item): string => $item->candidateId, $first['items']), array_map(static fn ($item): string => $item->candidateId, $second['items'])),
        );
    }

    public function test_review_page_filters_before_pagination_and_binds_cursor_to_state(): void
    {
        $rows = array_merge($this->rows(3, DictionaryCandidateState::NEEDS_REVIEW), $this->rows(2, DictionaryCandidateState::AMBIGUOUS, 10));
        $repository = new WpdbDictionaryCandidateRepository(new CandidatePageDatabase($rows));

        $page = $repository->pageForReview(1, null, DictionaryCandidateState::AMBIGUOUS);

        self::assertSame(2, $page['total']);
        self::assertTrue($page['has_more']);
        self::assertCount(1, $page['items']);
        $this->expectExceptionMessage('DICTIONARY_CANDIDATE_CURSOR_FILTER_MISMATCH');
        $repository->pageForReview(2, $page['next_cursor'], DictionaryCandidateState::NEEDS_REVIEW);
    }

    public function test_review_page_rejects_malformed_cursor(): void
    {
        $repository = new WpdbDictionaryCandidateRepository(new CandidatePageDatabase($this->rows(1)));

        $this->expectExceptionMessage('DICTIONARY_CANDIDATE_CURSOR_INVALID');
        $repository->pageForReview(10, 'not-a-cursor');
    }

    public function test_non_review_state_filter_does_not_expand_review_queue(): void
    {
        $repository = new WpdbDictionaryCandidateRepository(new CandidatePageDatabase($this->rows(1, DictionaryCandidateState::APPROVED)));

        $page = $repository->pageForReview(10, null, DictionaryCandidateState::APPROVED);

        self::assertSame([], $page['items']);
        self::assertSame(0, $page['total']);
        self::assertFalse($page['has_more']);
    }

    #[DataProvider('inventorySizes')]
    public function test_review_page_reports_complete_inventory_across_boundary_sizes(int $count): void
    {
        $database = new CandidatePageDatabase($this->rows($count));
        $repository = new WpdbDictionaryCandidateRepository($database);
        $seen = [];
        $cursor = null;
        do {
            $page = $repository->pageForReview(100, $cursor);
            foreach ($page['items'] as $item) $seen[] = $item->candidateId;
            $cursor = $page['next_cursor'];
        } while ($page['has_more']);

        self::assertSame($count, count($seen));
        self::assertCount($count, array_unique($seen));
        self::assertSame($count, $page['total']);
        self::assertFalse($page['has_more']);
    }

    /** @return list<array{int}> */
    public static function inventorySizes(): array
    {
        return [[0], [1], [95], [100], [101], [120], [201]];
    }

    /** @return list<array<string,mixed>> */
    private function rows(int $count, string $state = DictionaryCandidateState::NEEDS_REVIEW, int $offset = 0): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $number = $i + $offset;
            $id = sprintf('01a00000-0000-7000-8000-%012d', $number);
            $rows[] = [
                'candidate_uuid' => UuidCodec::toBinary($id),
                'normalized_term' => 'term-' . $number,
                'context_hash' => hash('sha256', '{}'),
                'raw_forms_json' => json_encode(['Term ' . $number]),
                'candidate_state' => $state,
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

final class CandidatePageDatabase
{
    public string $prefix = 'wp_';
    /** @var list<string> */
    public array $ids = [];
    private ?string $state = null;
    private ?string $after = null;
    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows)
    {
        foreach ($rows as $row) $this->ids[] = UuidCodec::fromBinary($row['candidate_uuid']);
    }
    public function prepare(string $query, mixed ...$args): string
    {
        $this->state = null;
        $this->after = null;
        $states = array_values(array_filter($args, static fn (mixed $arg): bool => is_string($arg) && DictionaryCandidateState::valid($arg)));
        if (count($states) === 1) $this->state = $states[0];
        foreach ($args as $arg) {
            if (is_string($arg) && strlen($arg) === 16) {
                try { $this->after = UuidCodec::fromBinary($arg); } catch (\Throwable) {}
            }
        }
        return $query;
    }
    public function get_var(string $query): mixed { return count($this->eligibleRows()); }
    public function get_results(string $query, mixed $output = null): array { return $this->eligibleRows(); }
    /** @return list<array<string,mixed>> */
    private function eligibleRows(): array
    {
        $rows = array_values(array_filter($this->rows, fn (array $row): bool => $this->state === null || $row['candidate_state'] === $this->state));
        if ($this->after !== null) $rows = array_values(array_filter($rows, fn (array $row): bool => UuidCodec::fromBinary($row['candidate_uuid']) > $this->after));
        usort($rows, static fn (array $left, array $right): int => strcmp(UuidCodec::fromBinary($left['candidate_uuid']), UuidCodec::fromBinary($right['candidate_uuid'])));
        return $rows;
    }
}
