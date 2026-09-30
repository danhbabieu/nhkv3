<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryResolver, DictionarySeedPlanner};
use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use PHPUnit\Framework\TestCase;

final class DictionarySeedPlannerTest extends TestCase
{
    public function test_seed_plan_resolves_reuses_deduplicates_and_preserves_lineage_without_writes(): void
    {
        $resolver = new DictionaryResolver(
            static fn (string $term): array => $term === 'existing label' ? [['concept_id' => 'concept-1', 'preferred_label' => 'Existing Label', 'destination_type' => 'model', 'destination_id' => 'model-1']] : [],
            static fn (string $term): array => $term === 'alias' ? [['concept_id' => 'concept-1', 'preferred_label' => 'Existing Label', 'destination_type' => 'model', 'destination_id' => 'model-1']] : [],
            static fn (): array => [],
            static fn (): array => [],
            static fn (): bool => false,
        );
        $packet = (new StructuredSemanticInterpreter())->interpret([
            'text' => 'Existing Label và alias.',
            'source_kind' => 'article',
            'source_identifier' => 'article:1',
            'metadata' => ['lexical_hints' => ['Existing Label', 'existing label', 'alias']],
        ]);

        $result = (new DictionarySeedPlanner($resolver))->plan($packet, ['source_family' => 'article:1']);

        self::assertTrue($result['read_only']);
        self::assertFalse($result['mutated']);
        self::assertSame('READ_ONLY_PLAN', $result['status']);
        self::assertCount(2, $result['items']);
        self::assertSame('RESOLVED_EXISTING', $result['items'][0]['classification']);
        self::assertSame(['Existing Label'], $result['items'][0]['raw_forms']);
        self::assertSame(['article:1'], $result['items'][0]['source_families']);
        self::assertSame('ALIAS_TO_EXISTING', $result['items'][1]['classification']);
        self::assertSame('concept-1', $result['items'][1]['resolution']['concept_id']);
    }

    public function test_seed_plan_fails_closed_for_ambiguity_and_keeps_unknown_valid_terms(): void
    {
        $resolver = new DictionaryResolver(
            static fn (string $term): array => $term === 'ambiguous' ? [['concept_id' => 'a'], ['concept_id' => 'b']] : [],
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );
        $packet = [
            'locale' => 'vi-VN',
            'source_context' => ['source_kind' => 'human_chat'],
            'semantic_query_seeds' => [
                ['raw_span' => 'Ambiguous', 'normalized_form' => 'ambiguous', 'category' => 'LEXICAL_TERM', 'locale' => 'vi-VN'],
                ['raw_span' => 'Valid Unknown', 'normalized_form' => 'valid unknown', 'category' => 'LEXICAL_TERM', 'locale' => 'vi-VN'],
            ],
        ];

        $result = (new DictionarySeedPlanner($resolver))->plan($packet, ['source_family' => 'chat:7']);

        self::assertSame(['AMBIGUOUS', 'NEW_LEXICAL_CANDIDATE'], array_column($result['items'], 'classification'));
        self::assertSame([], $result['items'][0]['resolution']['destination_ids']);
        self::assertSame('valid unknown', $result['items'][1]['normalized_form']);
        self::assertContains('AMBIGUOUS_CANONICAL_OWNER', $result['items'][0]['diagnostics']);
    }

    public function test_seed_plan_classifies_suppressed_editorial_and_noise_without_persisting(): void
    {
        $resolver = new DictionaryResolver(
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): array => [],
            static fn (string $term): bool => $term === 'suppressed term',
        );
        $packet = [
            'semantic_query_seeds' => [
                ['raw_span' => 'Suppressed Term', 'normalized_form' => 'suppressed term', 'category' => 'LEXICAL_TERM', 'locale' => 'vi-VN'],
                ['raw_span' => 'Editorial tail', 'normalized_form' => 'editorial tail', 'category' => 'EDITORIAL_SIGNAL', 'locale' => 'vi-VN'],
                ['raw_span' => 'noise fragment', 'normalized_form' => 'noise fragment', 'category' => 'NOISE', 'locale' => 'vi-VN'],
            ],
        ];

        $result = (new DictionarySeedPlanner($resolver))->plan($packet);

        self::assertSame(['SUPPRESSED', 'EDITORIAL_ONLY', 'NOISE'], array_column($result['items'], 'classification'));
        self::assertFalse($result['mutated']);
        self::assertSame(3, $result['aggregate']['total']);
    }

    public function test_seed_plan_exposes_canonical_reuse_and_action_without_creating_a_concept(): void
    {
        $resolver = new DictionaryResolver(
            static fn (): array => [],
            static fn (): array => [[
                'preferred_label' => 'ÔĐô 36/10',
                'destination_type' => 'variant',
                'destination_id' => 'variant-1',
            ]],
            static fn (): array => [],
            static fn (): array => [],
            static fn (): bool => false,
        );

        $result = (new DictionarySeedPlanner($resolver))->plan([
            'semantic_query_seeds' => [[
                'raw_span' => 'ÔĐô 36/10',
                'normalized_form' => 'ôđô 36/10',
                'category' => 'IDENTIFIER',
                'locale' => 'vi-VN',
            ]],
        ]);

        $item = $result['items'][0];
        self::assertSame('RESOLVED_EXISTING', $item['classification']);
        self::assertSame('RESOLVED', $item['resolution_status']);
        self::assertSame('variant', $item['resolved_destination_type']);
        self::assertSame('variant-1', $item['resolved_destination_id']);
        self::assertSame('REUSE_EXISTING', $item['suggested_action']);
        self::assertNull($item['resolved_dictionary_concept_id']);
        self::assertFalse($result['mutated']);
    }
}
