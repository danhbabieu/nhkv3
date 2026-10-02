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

    public function test_aggregate_preserves_raw_forms_and_separates_derived_sources_from_independent_sources(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'knowledge:1', 'source_family' => 'source:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'context' => ['lexical_hints' => ['Alpha']]],
            ['source_id' => 'knowledge:2', 'source_family' => 'derived:copy', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'ALPHA', 'raw_or_derived' => 'DERIVED', 'lineage' => ['parent_source_id' => 'knowledge:1', 'source_family' => 'source:a'], 'context' => ['lexical_hints' => ['ALPHA']]],
            ['source_id' => 'knowledge:3', 'source_family' => 'source:b', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alfa', 'context' => ['lexical_hints' => ['Alfa']]],
        ]);
        $result = $this->coordinator(['KNOWLEDGE' => $reader], static fn (): array => [])->audit('KNOWLEDGE', null, 10);

        $alpha = array_values(array_filter($result['items'], static fn (array $item): bool => $item['normalized_form'] === 'alpha'))[0];
        self::assertSame(2, $alpha['occurrences']);
        self::assertSame(2, $alpha['source_count']);
        self::assertSame(1, $alpha['independent_source_count']);
        self::assertSame(['Alpha', 'ALPHA'], $alpha['raw_forms']);
        self::assertSame(['knowledge:1', 'knowledge:2'], $alpha['source_ids']);
        self::assertSame(['source:a', 'derived:copy'], $alpha['source_families']);
        self::assertSame(['parent_source_id' => 'knowledge:1', 'source_family' => 'source:a'], $alpha['derived_lineage']);
    }

    public function test_raw_editorial_context_lineage_remains_independent_and_is_not_reported_as_derived(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'article:18', 'source_family' => 'article:18', 'source_kind' => 'ARTICLE', 'raw_text' => 'Alpha', 'raw_or_derived' => 'RAW', 'lineage' => ['source_family' => 'ARTICLE', 'editorial_context' => 'publish'], 'context' => ['lexical_hints' => ['Alpha']]],
        ]);

        $result = $this->coordinator(['ARTICLE' => $reader], static fn (): array => [])->audit('ARTICLE', null, 10);
        $item = $result['items'][0];

        self::assertSame(1, $item['independent_source_count']);
        self::assertSame([], $item['derived_lineage']);
        self::assertSame('KNOWN', $item['provenance_status']);
    }

    public function test_independent_raw_sources_are_distinct_but_same_canonical_origin_is_not_counted_twice(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'source:a', 'source_family' => 'family:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'raw_or_derived' => 'RAW', 'canonical_origin_id' => 'origin:a', 'context' => ['lexical_hints' => ['Alpha']]],
            ['source_id' => 'source:b', 'source_family' => 'family:b', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'ALPHA', 'raw_or_derived' => 'RAW', 'canonical_origin_id' => 'origin:b', 'context' => ['lexical_hints' => ['ALPHA']]],
            ['source_id' => 'replay:a', 'source_family' => 'family:replay', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'raw_or_derived' => 'RAW', 'canonical_origin_id' => 'origin:a', 'context' => ['lexical_hints' => ['Alpha']]],
        ]);

        $result = $this->coordinator(['KNOWLEDGE' => $reader], static fn (): array => [])->audit('KNOWLEDGE', null, 10);
        $item = $result['items'][0];

        self::assertSame(3, $item['source_count']);
        self::assertSame(2, $item['independent_source_count']);
        self::assertSame('KNOWN', $item['provenance_status']);
    }

    public function test_raw_derived_raw_case_counts_three_sources_but_only_two_independent_origins(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'source:a', 'source_family' => 'family:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'raw_or_derived' => 'RAW', 'canonical_origin_id' => 'origin:a', 'context' => ['lexical_hints' => ['Alpha']]],
            ['source_id' => 'derived:copy', 'source_family' => 'family:derived', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'ALPHA', 'raw_or_derived' => 'DERIVED', 'lineage' => ['parent_source_id' => 'source:a', 'canonical_origin_id' => 'origin:a'], 'canonical_origin_id' => 'origin:a', 'context' => ['lexical_hints' => ['ALPHA']]],
            ['source_id' => 'source:b', 'source_family' => 'family:b', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'alpha', 'raw_or_derived' => 'RAW', 'canonical_origin_id' => 'origin:b', 'context' => ['lexical_hints' => ['alpha']]],
        ]);

        $result = $this->coordinator(['KNOWLEDGE' => $reader], static fn (): array => [])->audit('KNOWLEDGE', null, 10);
        $item = $result['items'][0];

        self::assertSame(3, $item['source_count']);
        self::assertSame(2, $item['independent_source_count']);
    }

    public function test_insufficient_provenance_is_explicitly_uncertain_without_inventing_corroboration(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_family' => 'family:unknown', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'raw_or_derived' => 'MAYBE', 'context' => ['lexical_hints' => ['Alpha']]],
        ]);

        $result = $this->coordinator(['KNOWLEDGE' => $reader], static fn (): array => [])->audit('KNOWLEDGE', null, 10);
        $item = $result['items'][0];

        self::assertSame('UNCERTAIN', $item['provenance_status']);
        self::assertContains('PROVENANCE_INDEPENDENCE_UNCERTAIN', $item['diagnostics']);
    }

    public function test_missing_identity_or_invalid_provenance_is_uncertain_and_not_independent(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_family' => 'family:missing', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'raw_or_derived' => 'RAW', 'context' => ['lexical_hints' => ['Alpha']]],
            ['source_id' => 'source:invalid', 'source_family' => 'family:invalid', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'raw_or_derived' => 'MAYBE', 'context' => ['lexical_hints' => ['Alpha']]],
        ]);

        $result = $this->coordinator(['KNOWLEDGE' => $reader], static fn (): array => [])->audit('KNOWLEDGE', null, 10);
        $item = $result['items'][0];

        self::assertSame('UNCERTAIN', $item['provenance_status']);
        self::assertSame(0, $item['independent_source_count']);
        self::assertContains('PROVENANCE_INDEPENDENCE_UNCERTAIN', $item['diagnostics']);
    }

    public function test_derived_observations_retain_each_parent_lineage_and_source_observation_packet(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'derived:a', 'source_family' => 'family:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'raw_or_derived' => 'DERIVED', 'lineage' => ['parent_source_id' => 'parent:a', 'source_family' => 'family:a'], 'context' => ['lexical_hints' => ['Alpha']]],
            ['source_id' => 'derived:b', 'source_family' => 'family:b', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'ALPHA', 'raw_or_derived' => 'DERIVED', 'lineage' => ['parent_source_id' => 'parent:b', 'source_family' => 'family:b'], 'context' => ['lexical_hints' => ['ALPHA']]],
        ]);

        $result = $this->coordinator(['KNOWLEDGE' => $reader], static fn (): array => [])->audit('KNOWLEDGE', null, 10);
        $item = $result['items'][0];

        self::assertSame([
            ['source_id' => 'derived:a', 'source_family' => 'family:a', 'raw_forms' => ['Alpha'], 'occurrences' => 1, 'lineage' => ['parent_source_id' => 'parent:a', 'source_family' => 'family:a']],
            ['source_id' => 'derived:b', 'source_family' => 'family:b', 'raw_forms' => ['ALPHA'], 'occurrences' => 1, 'lineage' => ['parent_source_id' => 'parent:b', 'source_family' => 'family:b']],
        ], $item['source_observations']);
        self::assertSame([
            ['parent_source_id' => 'parent:a', 'source_family' => 'family:a'],
            ['parent_source_id' => 'parent:b', 'source_family' => 'family:b'],
        ], $item['derived_lineages']);
    }

    public function test_independent_source_count_is_explicitly_page_local_across_cursor_calls(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'source:a', 'source_family' => 'family:a', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'context' => ['lexical_hints' => ['Alpha']]],
            ['source_id' => 'source:b', 'source_family' => 'family:b', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Alpha', 'context' => ['lexical_hints' => ['Alpha']]],
        ]);
        $coordinator = $this->coordinator(['KNOWLEDGE' => $reader], static fn (): array => []);

        $first = $coordinator->audit('KNOWLEDGE', null, 1);
        $second = $coordinator->audit('KNOWLEDGE', $first['next_cursor'], 1);

        self::assertSame('AUDIT_PAGE', $first['aggregate']['independent_source_count_scope']);
        self::assertSame('AUDIT_PAGE', $first['items'][0]['independent_source_count_scope']);
        self::assertSame(1, $first['items'][0]['independent_source_count']);
        self::assertSame(1, $second['items'][0]['independent_source_count']);
        self::assertSame('AUDIT_PAGE', $second['aggregate']['independent_source_count_scope']);
    }

    public function test_observation_only_is_counted_as_safe_diagnostic_without_creating_a_dictionary_candidate(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'source:observation', 'source_family' => 'family:observation', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'carillon', 'context' => []],
        ]);

        $result = $this->coordinator(['KNOWLEDGE' => $reader], static fn (): array => [])->audit('KNOWLEDGE', null, 10);

        self::assertSame(1, $result['aggregate']['observation_only_count']);
        self::assertSame([], $result['items']);
        self::assertStringNotContainsString('carillon', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_observation_only_count_is_page_local_across_multiple_article_cursor_pages(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'article:holdout-1', 'source_family' => 'article:holdout-1', 'source_kind' => 'ARTICLE', 'raw_text' => 'carillon', 'context' => []],
            ['source_id' => 'article:holdout-2', 'source_family' => 'article:holdout-2', 'source_kind' => 'ARTICLE', 'raw_text' => 'astrolabe', 'context' => []],
        ]);
        $coordinator = $this->coordinator(['ARTICLE' => $reader], static fn (): array => []);

        $first = $coordinator->audit('ARTICLE', null, 1);
        $second = $coordinator->audit('ARTICLE', $first['next_cursor'], 1);

        self::assertSame(1, $first['aggregate']['observation_only_count']);
        self::assertSame(1, $second['aggregate']['observation_only_count']);
        self::assertSame('AUDIT_PAGE', $first['aggregate']['independent_source_count_scope']);
        self::assertSame('AUDIT_PAGE', $second['aggregate']['independent_source_count_scope']);
    }

    public function test_ambiguity_fails_closed_and_private_source_text_is_not_serialized(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'knowledge:1', 'source_family' => 'private:evidence', 'source_kind' => 'KNOWLEDGE', 'raw_text' => 'Ambiguous Term', 'context' => ['private_source_text' => 'SECRET CLAIM TEXT']],
        ]);
        $coordinator = $this->coordinator(['KNOWLEDGE' => $reader], static fn (string $term): array => $term === 'ambiguous term' ? [['preferred_label' => 'A'], ['preferred_label' => 'B']] : []);
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
        self::assertSame('resolver_planner', $result['diagnostics']['source_diagnostics'][0]['stage']);
        self::assertSame('article:19', $result['diagnostics']['source_diagnostics'][0]['source_id']);
        self::assertArrayNotHasKey('message', $result['diagnostics']['source_diagnostics'][0]);
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

    public function test_article_source_over_twelve_thousand_utf8_bytes_does_not_kill_audit(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => 'article:19', 'source_family' => 'article:19', 'source_kind' => 'ARTICLE', 'raw_text' => str_repeat('Odo 36/10 và ÔĐô 36/10. ', 600), 'context' => []],
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

    public function test_article_planning_bounds_resolver_work_for_many_lexical_seeds(): void
    {
        $phrases = [];
        for ($index = 0; $index < 160; $index++) {
            $value = $index;
            $suffix = '';
            do {
                $suffix = chr(97 + ($value % 26)) . $suffix;
                $value = intdiv($value, 26) - 1;
            } while ($value >= 0);
            $phrases[] = 'Alpha' . $suffix . ' Device';
        }
        $resolverCalls = 0;
        $resolver = new DictionaryResolver(
            static fn (): array => [],
            static function () use (&$resolverCalls): array { $resolverCalls++; return []; },
            static function () use (&$resolverCalls): array { $resolverCalls++; return []; },
            static function () use (&$resolverCalls): array { $resolverCalls++; return []; },
            static fn (): bool => false,
        );
        $result = (new DictionarySeedCorpusAuditCoordinator(
            ['ARTICLE' => new FakeDictionaryCorpusReader([['source_id' => 'article:long', 'source_family' => 'article:long', 'source_kind' => 'ARTICLE', 'raw_text' => implode('. ', $phrases), 'context' => []]])],
            new StructuredSemanticInterpreter(),
            new DictionarySeedPlanner($resolver),
        ))->audit('ARTICLE', null, 1);

        self::assertSame(1, $result['sources_scanned']);
        self::assertLessThanOrEqual(16, intdiv($resolverCalls, 3));
        self::assertSame('AVAILABLE', $result['status']);
        self::assertTrue($result['read_only']);
        self::assertFalse($result['mutated']);
        self::assertSame('CORPUS_SOURCE_SEED_BUDGET_REACHED', $result['diagnostics']['source_diagnostics'][0]['code']);
    }

    public function test_article_seed_budget_continues_same_article_without_loss_or_duplicate_before_next_article(): void
    {
        $phrases = [];
        for ($index = 1; $index <= 20; $index++) $phrases[] = 'Seed ' . $index . ' Device';
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => '18', 'source_family' => 'article:18', 'source_kind' => 'ARTICLE', 'raw_text' => 'Article Eighteen', 'context' => ['lexical_hints' => ['Article Eighteen']]],
            ['source_id' => '19', 'source_family' => 'article:19', 'source_kind' => 'ARTICLE', 'raw_text' => implode('. ', $phrases), 'context' => ['lexical_hints' => $phrases]],
            ['source_id' => '20', 'source_family' => 'article:20', 'source_kind' => 'ARTICLE', 'raw_text' => 'Article Twenty', 'context' => ['lexical_hints' => ['Article Twenty']]],
            ['source_id' => '41', 'source_family' => 'article:41', 'source_kind' => 'ARTICLE', 'raw_text' => 'Article Forty One', 'context' => ['lexical_hints' => ['Article Forty One']]],
        ]);
        $coordinator = $this->coordinator(['ARTICLE' => $reader], static fn (): array => []);

        $article18 = $coordinator->audit('ARTICLE', null, 1);
        $article19First = $coordinator->audit('ARTICLE', $article18['next_cursor'], 1);
        $article19Second = $coordinator->audit('ARTICLE', $article19First['next_cursor'], 1);
        $article20 = $coordinator->audit('ARTICLE', $article19Second['next_cursor'], 1);
        $article41 = $coordinator->audit('ARTICLE', $article20['next_cursor'], 1);

        $firstBatch = array_column($article19First['items'], 'normalized_form');
        $secondBatch = array_column($article19Second['items'], 'normalized_form');
        self::assertCount(16, $firstBatch);
        self::assertCount(4, $secondBatch);
        self::assertSame([], array_intersect($firstBatch, $secondBatch));
        self::assertCount(20, array_unique(array_merge($firstBatch, $secondBatch)));
        self::assertSame('19', $article19First['diagnostics']['source_diagnostics'][0]['source_id']);
        self::assertSame('CORPUS_SOURCE_SEED_BUDGET_REACHED', $article19First['diagnostics']['source_diagnostics'][0]['code']);
        self::assertSame('article twenty', $article20['items'][0]['normalized_form']);
        self::assertSame('article forty one', $article41['items'][0]['normalized_form']);
        foreach ([$article18, $article19First, $article19Second, $article20, $article41] as $result) {
            self::assertTrue($result['read_only']);
            self::assertFalse($result['mutated']);
        }
    }

    public function test_article_seed_cursor_fails_closed_when_article_changes_between_batches(): void
    {
        $phrases = [];
        for ($index = 1; $index <= 20; $index++) $phrases[] = 'Seed ' . $index . ' Device';
        $rows = [['source_id' => '19', 'source_family' => 'article:19', 'source_kind' => 'ARTICLE', 'raw_text' => implode('. ', $phrases), 'context' => ['lexical_hints' => $phrases]]];
        $reader = new class($rows) implements DictionaryCorpusSourceReader {
            public function __construct(private array $rows) {}
            public function page(?string $after, int $limit): array { return ['items' => $this->rows, 'has_more' => false, 'next_cursor' => null]; }
            public function change(): void { $this->rows[0]['raw_text'] .= '. Changed'; }
        };
        $coordinator = $this->coordinator(['ARTICLE' => $reader], static fn (): array => []);
        $first = $coordinator->audit('ARTICLE', null, 1);
        $reader->change();

        $this->expectExceptionMessage('DICTIONARY_SEED_CORPUS_CURSOR_INVALIDATED');
        $coordinator->audit('ARTICLE', $first['next_cursor'], 1);
    }

    public function test_article_19_generic_prose_is_filtered_before_resolver_without_affecting_article_18_or_41(): void
    {
        $reader = new FakeDictionaryCorpusReader([
            ['source_id' => '18', 'source_family' => 'article:18', 'source_kind' => 'ARTICLE', 'raw_text' => 'Mặt số lớn.', 'context' => []],
            ['source_id' => '19', 'source_family' => 'article:19', 'source_kind' => 'ARTICLE', 'raw_text' => 'Odo 36/10 là dòng được nhiều người yêu thích. Đây là một chiếc đồng hồ có giá trị sưu tầm cao và tương đối hiếm. Cần tách riêng độ hiếm và giá trị sưu tầm, vì hai thuộc tính không luôn đồng nghĩa.', 'context' => []],
            ['source_id' => '41', 'source_family' => 'article:41', 'source_kind' => 'ARTICLE', 'raw_text' => 'Bộ thoát và Odo 36/10.', 'context' => []],
        ]);
        $resolverCalls = [];
        $coordinator = $this->coordinator(['ARTICLE' => $reader], static function (string $term) use (&$resolverCalls): array {
            $resolverCalls[] = $term;
            return [];
        });

        $article18 = $coordinator->audit('ARTICLE', null, 1);
        $article19 = $coordinator->audit('ARTICLE', $article18['next_cursor'], 1);
        $article41 = $coordinator->audit('ARTICLE', $article19['next_cursor'], 1);

        self::assertSame(['mặt số lớn'], array_column($article18['items'], 'normalized_form'));
        self::assertSame(['odo 36/10', 'đồng hồ'], array_column($article19['items'], 'normalized_form'));
        self::assertSame(['bộ thoát', 'odo 36/10'], array_column($article41['items'], 'normalized_form'));
        self::assertCount(5, $resolverCalls);
        foreach (['nhiều người yêu thích', 'đây', 'giá trị sưu tầm cao', 'tương đối', 'cần tách riêng độ', 'thuộc tính', 'luôn đồng nghĩa'] as $fragment) {
            self::assertNotContains($fragment, $resolverCalls);
        }
        foreach ([$article18, $article19, $article41] as $result) {
            self::assertTrue($result['read_only']);
            self::assertFalse($result['mutated']);
        }
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
