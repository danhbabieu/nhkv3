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
