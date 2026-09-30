<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryResolver, DictionarySeedPlanner};
use NHK\Core\Application\Semantic\{SemanticEnrichmentPlanner, StructuredSemanticInterpreter};
use PHPUnit\Framework\TestCase;

final class SemanticEnrichmentPlannerTest extends TestCase
{
    public function test_read_only_enrichment_resolves_dictionary_discovers_graph_and_keeps_only_applicable_claims(): void
    {
        $dictionary = new DictionarySeedPlanner(new DictionaryResolver(
            static fn (): array => [['concept_id' => 'concept-1', 'preferred_label' => 'Canonical Label', 'destination_type' => 'model', 'destination_id' => 'model-1']],
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        ));
        $planner = new SemanticEnrichmentPlanner(
            $dictionary,
            static fn (array $seed): array => [['id' => 'model-1', 'type' => 'model', 'term' => $seed['normalized_form']]],
            static fn (array $owner): array => [['source_id' => $owner['id'], 'target_id' => 'component-1', 'predicate' => 'has_component']],
            static fn (array $owner): array => [
                ['claim_id' => 'claim-valid', 'subject_id' => $owner['id'], 'scope' => 'model', 'applicability' => 'applicable', 'evidence_status' => 'eligible', 'text' => 'valid'],
                ['claim_id' => 'claim-wrong-scope', 'subject_id' => $owner['id'], 'scope' => 'brand', 'applicability' => 'inapplicable', 'evidence_status' => 'eligible', 'text' => 'wrong scope'],
                ['claim_id' => 'claim-no-evidence', 'subject_id' => $owner['id'], 'scope' => 'model', 'applicability' => 'applicable', 'evidence_status' => 'missing', 'text' => 'unsupported'],
            ],
        );

        $result = $planner->plan((new StructuredSemanticInterpreter())->interpret([
            'text' => 'Existing term.',
            'source_kind' => 'article',
            'source_identifier' => 'article:1',
            'hints' => ['Existing term'],
        ]));

        self::assertTrue($result['read_only']);
        self::assertFalse($result['mutated']);
        self::assertSame('AVAILABLE', $result['status']);
        self::assertCount(1, $result['dictionary']['items']);
        self::assertSame('ALIAS_TO_EXISTING', $result['dictionary']['items'][0]['classification']);
        self::assertCount(1, $result['owners']);
        self::assertCount(1, $result['graph_candidates']);
        self::assertSame(['claim-valid'], array_column($result['knowledge']['applicable'], 'claim_id'));
        self::assertSame(['claim-wrong-scope', 'claim-no-evidence'], array_column($result['knowledge']['rejected'], 'claim_id'));
    }

    public function test_ambiguous_or_derived_input_fails_closed_without_claim_reuse(): void
    {
        $dictionary = new DictionarySeedPlanner(new DictionaryResolver(
            static fn (): array => [['concept_id' => 'a'], ['concept_id' => 'b']],
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        ));
        $knowledgeCalls = 0;
        $planner = new SemanticEnrichmentPlanner($dictionary, null, null, static function () use (&$knowledgeCalls): array { $knowledgeCalls++; return []; });

        $result = $planner->plan((new StructuredSemanticInterpreter())->interpret([
            'text' => 'Ambiguous term.',
            'source_kind' => 'generated_article_prose',
            'lineage' => ['source_family' => 'article:1', 'derived' => true],
            'hints' => ['Ambiguous term'],
        ]));

        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertContains('DERIVED_PROSE_NOT_INDEPENDENT_EVIDENCE', $result['diagnostics']);
        self::assertSame(0, $knowledgeCalls);
        self::assertSame([], $result['knowledge']['applicable']);
    }

    public function test_unknown_term_is_preserved_and_retrieval_failure_is_unavailable(): void
    {
        $dictionary = new DictionarySeedPlanner(new DictionaryResolver(
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        ));
        $planner = new SemanticEnrichmentPlanner($dictionary, null, null, static function (): array { throw new \RuntimeException('runtime unavailable'); });

        $result = $planner->plan([
            'source_context' => ['source_kind' => 'human_chat'],
            'semantic_query_seeds' => [['raw_span' => 'Unknown Term', 'normalized_form' => 'unknown term', 'category' => 'LEXICAL_TERM', 'locale' => 'vi-VN']],
        ]);

        self::assertSame('UNAVAILABLE', $result['status']);
        self::assertSame('unknown term', $result['dictionary']['items'][0]['normalized_form']);
        self::assertContains('KNOWLEDGE_RETRIEVAL_UNAVAILABLE', $result['diagnostics']);
        self::assertFalse($result['mutated']);
    }
}
