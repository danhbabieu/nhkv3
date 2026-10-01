<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryCorpusSourceReader, DictionaryResolver, DictionarySeedCorpusAuditCoordinator, DictionarySeedPlanner};
use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use NHK\Core\Domain\Dictionary\DictionaryCandidate;
use NHK\Core\Contracts\Dictionary\DictionaryCandidateRepository;
use PHPUnit\Framework\TestCase;

final class DictionarySeedCorpusAuditTest extends TestCase
{
    public function test_multi_page_knowledge_audit_uses_deterministic_cursor_and_aggregates_duplicate_terms(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'knowledge:1', 'source_family' => 'source:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'context' => ['lexical_hints' => ['Alpha']]],
            ['source_id' => 'knowledge:2', 'source_family' => 'source:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'context' => ['lexical_hints' => ['Alpha']]],
            ['source_id' => 'knowledge:3', 'source_family' => 'source:b', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Beta', 'context' => ['lexical_hints' => ['Beta']]],
        ]);
        $coordinator = $this->coordinator(['KNOWLEDGE' => $reader], static fn (string $term): array => $term === 'alpha' ? [['preferred_label' => 'Alpha', 'destination_type' => 'model', 'destination_id' => 'model-1']] : []);

        $first = $coordinator->audit('KNOWLEDGE', null, 2);
        self::assertSame('AVAILABLE', $first['status']);
        self::assertSame(2, $first['sources_scanned']);
        self::assertSame(2, $first['lexical_observations']);
        self::assertSame(1, $first['unique_normalized_terms']);
        self::assertSame(2, $first['items'][0]['occurrences']);
        self::assertSame(2, $first['items'][0]['source_count']);
        self::assertSame(1, $first['items'][0]['source_family_count']);
        self::assertNotNull($first['next_cursor']);
        self::assertSame($first['next_cursor'], $coordinator->audit('KNOWLEDGE', null, 2)['next_cursor']);

        $second = $coordinator->audit('KNOWLEDGE', $first['next_cursor'], 2);
        self::assertSame(1, $second['sources_scanned']);
        self::assertSame('beta', $second['items'][0]['normalized_form']);
        self::assertNull($second['next_cursor']);
    }

    public function test_same_source_replay_is_deduplicated_and_legacy_queue_does_not_change_current_interpretation(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'knowledge:1', 'source_family' => 'source:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Current', 'context' => ['lexical_hints' => ['Current']]],
            ['source_id' => 'knowledge:1', 'source_family' => 'source:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Current', 'context' => ['lexical_hints' => ['Current']]],
        ]);
        $queue = new class implements DictionaryCandidateRepository {
            public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate { throw new \LogicException('write'); }
            public function suppressed(string $normalizedTerm, string $contextHash): bool { throw new \LogicException('not used'); }
            public function listForReview(int $limit = 100): array { return [new DictionaryCandidate('11111111-1111-4111-8111-111111111111', 'legacy term', str_repeat('a', 64), ['Legacy Term'])]; }
            public function findById(string $candidateId): ?DictionaryCandidate { return null; }
            public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate { throw new \LogicException('write'); }
        };
        $coordinator = $this->coordinator(['KNOWLEDGE' => $reader], static fn (): array => [], $queue);
        $result = $coordinator->audit('KNOWLEDGE', null, 10);

        self::assertSame(1, $result['sources_scanned']);
        self::assertSame(1, $result['lexical_observations']);
        self::assertSame(1, $result['items'][0]['occurrences']);
        self::assertSame(['legacy term'], $result['legacy_candidate_comparison']['legacy_only']);
        self::assertSame('NEW_LEXICAL_CANDIDATE', $result['items'][0]['classification']);
        self::assertFalse($result['mutated']);
    }

    public function test_ambiguity_fails_closed_and_private_source_text_is_not_serialized(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'knowledge:1', 'source_family' => 'private:evidence', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Ambiguous', 'context' => ['private_source_text' => 'SECRET CLAIM TEXT']],
        ]);
        $coordinator = $this->coordinator(['KNOWLEDGE' => $reader], static fn (string $term): array => $term === 'ambiguous' ? [['preferred_label' => 'A'], ['preferred_label' => 'B']] : []);
        $result = $coordinator->audit('KNOWLEDGE', null, 10);
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);

        self::assertSame('AMBIGUOUS', $result['items'][0]['classification']);
        self::assertSame('REVIEW_AMBIGUITY', $result['items'][0]['suggested_action']);
        self::assertStringNotContainsString('SECRET CLAIM TEXT', $serialized);
        self::assertTrue($result['read_only']);
        self::assertFalse($result['mutated']);
    }

    public function test_source_without_lexical_term_is_still_counted_and_cursor_is_deterministic(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'knowledge:1', 'source_family' => 'source:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => '', 'context' => []],
            ['source_id' => 'knowledge:2', 'source_family' => 'source:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Valid Term', 'context' => ['lexical_hints' => ['Valid Term']]],
        ]);
        $coordinator = $this->coordinator(['KNOWLEDGE' => $reader], static fn (): array => []);

        $result = $coordinator->audit('KNOWLEDGE', null, 10);

        self::assertSame(2, $result['sources_scanned']);
        self::assertSame(1, $result['lexical_observations']);
        self::assertSame('valid term', $result['items'][0]['normalized_form']);
    }

    public function test_editorial_and_noise_signals_are_suppressed_in_seed_plan(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'knowledge:1', 'source_family' => 'source:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Hãy hỏi và bộ máy hoàn toàn nguyên bản.', 'context' => []],
        ]);
        $result = $this->coordinator(['KNOWLEDGE' => $reader], static fn (): array => [])->audit('KNOWLEDGE', null, 10);
        $classifications = array_column($result['items'], 'classification', 'normalized_form');

        self::assertSame('EDITORIAL_ONLY', $classifications['hãy hỏi']);
        self::assertSame('NOISE', $classifications['bộ máy hoàn toàn nguyên bản']);
        self::assertSame(1, $result['aggregate']['suppressed_editorial']);
        self::assertSame(1, $result['aggregate']['suppressed_noise']);
    }

    public function test_planner_failure_is_bounded_to_one_source_and_cursor_continues(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'article:19', 'source_family' => 'article:19', 'source_kind' => 'ARTICLE', 'raw_text' => 'Broken Term', 'context' => []],
            ['source_id' => 'article:20', 'source_family' => 'article:20', 'source_kind' => 'ARTICLE', 'raw_text' => 'Valid Term', 'context' => []],
        ]);
        $resolver = new DictionaryResolver(
            static fn (): array => [],
            static function (string $term): array {
                if (mb_strtolower($term) === 'broken term') throw new \RuntimeException('resolver failed');
                return [];
            },
            static fn (): array => [],
            static fn (): array => [],
            static fn (): bool => false,
        );
        $coordinator = new DictionarySeedCorpusAuditCoordinator(['ARTICLE' => $reader], new StructuredSemanticInterpreter(), new DictionarySeedPlanner($resolver));

        $result = $coordinator->audit('ARTICLE', null, 10);

        self::assertSame(2, $result['sources_scanned']);
        self::assertSame('CORPUS_SOURCE_PLANNING_FAILED', $result['diagnostics']['source_diagnostics'][0]['code']);
        self::assertSame('article:19', $result['diagnostics']['source_diagnostics'][0]['source_id']);
        self::assertSame('valid term', $result['items'][0]['normalized_form']);
        self::assertFalse($result['mutated']);
    }

    public function test_article_cursor_continues_after_faulty_source_between_two_valid_articles(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => '18', 'source_family' => 'article:18', 'source_kind' => 'ARTICLE', 'raw_text' => 'Valid Before', 'context' => []],
            ['source_id' => '19', 'source_family' => 'article:19', 'source_kind' => 'ARTICLE', 'raw_text' => 'Broken Term', 'context' => []],
            ['source_id' => '20', 'source_family' => 'article:20', 'source_kind' => 'ARTICLE', 'raw_text' => 'Valid After', 'context' => []],
        ]);
        $resolver = new DictionaryResolver(
            static fn (): array => [],
            static function (string $term): array {
                if (mb_strtolower($term) === 'broken term') throw new \RuntimeException('resolver failed');
                return [];
            },
            static fn (): array => [],
            static fn (): array => [],
            static fn (): bool => false,
        );
        $coordinator = new DictionarySeedCorpusAuditCoordinator(['ARTICLE' => $reader], new StructuredSemanticInterpreter(), new DictionarySeedPlanner($resolver));

        $before = $coordinator->audit('ARTICLE', null, 1);
        $fault = $coordinator->audit('ARTICLE', $before['next_cursor'], 1);
        $after = $coordinator->audit('ARTICLE', $fault['next_cursor'], 1);

        self::assertSame(1, $before['sources_scanned']);
        self::assertSame('valid before', $before['items'][0]['normalized_form']);
        self::assertSame('CORPUS_SOURCE_PLANNING_FAILED', $fault['diagnostics']['source_diagnostics'][0]['code']);
        self::assertSame(1, $fault['sources_scanned']);
        self::assertSame('valid after', $after['items'][0]['normalized_form']);
        self::assertTrue($fault['read_only']);
        self::assertFalse($fault['mutated']);
    }

    public function test_article_source_with_roughly_ten_thousand_characters_does_not_kill_audit(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'article:19', 'source_family' => 'article:19', 'source_kind' => 'ARTICLE', 'raw_text' => str_repeat('Odo 36/10 và ÔĐô 36/10. ', 430), 'context' => []],
            ['source_id' => 'article:20', 'source_family' => 'article:20', 'source_kind' => 'ARTICLE', 'raw_text' => 'Valid Term', 'context' => []],
        ]);
        $coordinator = $this->coordinator(['ARTICLE' => $reader], static fn (): array => []);

        $result = $coordinator->audit('ARTICLE', null, 2);

        self::assertSame(2, $result['sources_scanned']);
        self::assertSame('AVAILABLE', $result['status']);
        self::assertFalse($result['mutated']);
    }

    public function test_invalid_resolver_output_cannot_break_serialization_or_cursor_progress(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'article:bad', 'source_family' => 'article:bad', 'source_kind' => 'ARTICLE', 'raw_text' => 'Broken Term', 'context' => []],
            ['source_id' => 'article:next', 'source_family' => 'article:next', 'source_kind' => 'ARTICLE', 'raw_text' => 'Valid Term', 'context' => []],
        ]);
        $resolver = new DictionaryResolver(
            static fn (): array => [],
            static fn (): array => [['preferred_label' => 'Valid Term', 'destination_type' => 'model', 'destination_id' => "\xB1"]],
            static fn (): array => [],
            static fn (): array => [],
            static fn (): bool => false,
        );
        $coordinator = new DictionarySeedCorpusAuditCoordinator(['ARTICLE' => $reader], new StructuredSemanticInterpreter(), new DictionarySeedPlanner($resolver));

        $result = $coordinator->audit('ARTICLE', null, 1);
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString("\xB1", $serialized);
        self::assertNotNull($result['next_cursor']);
        $next = $coordinator->audit('ARTICLE', $result['next_cursor'], 1);
        self::assertSame(1, $next['sources_scanned']);
        self::assertSame('valid term', $next['items'][0]['normalized_form']);
        self::assertTrue($next['read_only']);
        self::assertFalse($next['mutated']);
    }

    public function test_source_diagnostics_are_bounded_without_dropping_source_count(): void
    {
        $rows = [];
        for ($index = 1; $index <= 100; $index++) {
            $rows[] = ['source_id' => 'article:' . $index, 'source_family' => 'article:' . $index, 'source_kind' => 'ARTICLE', 'raw_text' => '', 'source_error' => 'ARTICLE_SOURCE_UNAVAILABLE', 'context' => []];
        }
        $result = $this->coordinator(['ARTICLE' => new FakeDictionaryCorpusReader($rows)], static fn (): array => [])->audit('ARTICLE', null, 100);

        self::assertSame(100, $result['sources_scanned']);
        self::assertCount(50, $result['diagnostics']['source_diagnostics']);
        self::assertTrue($result['read_only']);
        self::assertFalse($result['mutated']);
    }

    /** @param array<string,DictionaryCorpusSourceReader> $readers @param callable(string):array $entityLookup */
    private function coordinator(array $readers, callable $entityLookup, ?DictionaryCandidateRepository $queue = null): DictionarySeedCorpusAuditCoordinator
    {
        $resolver = new DictionaryResolver(static fn (): array => [], $entityLookup, static fn (): array => [], static fn (): array => [], static fn (): bool => false);
        return new DictionarySeedCorpusAuditCoordinator($readers, new StructuredSemanticInterpreter(), new DictionarySeedPlanner($resolver), $queue);
    }
}

final class FakeDictionaryCorpusReader implements DictionaryCorpusSourceReader
{
    public function __construct(private array $rows) {}

    public function page(?string $after, int $limit): array
    {
        $rows = array_values(array_filter($this->rows, static fn (array $row): bool => $after === null || strcmp((string) $row['source_id'], $after) > 0));
        $page = array_slice($rows, 0, $limit + 1);
        return ['items' => array_slice($page, 0, $limit), 'has_more' => count($page) > $limit];
    }
}
