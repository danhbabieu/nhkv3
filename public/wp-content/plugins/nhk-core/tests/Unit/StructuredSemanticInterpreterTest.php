<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{DerivedLineageGuard, StructuredSemanticInterpreter, TextInputInterpreter, UniversalInputEnvelope};
use PHPUnit\Framework\TestCase;

final class StructuredSemanticInterpreterTest extends TestCase
{
    public function test_editorial_signals_do_not_become_dictionary_candidates(): void
    {
        $value = (new StructuredSemanticInterpreter())->interpret([
            'text' => 'bộ truyền động cũng khá đặc biệt',
            'source_kind' => 'human_chat',
        ])->toArray();

        self::assertContains('bộ truyền động', array_column($value['lexical_spans'], 'normalized_term'));
        self::assertNotContains('khá đặc biệt', array_column($value['semantic_query_seeds'], 'normalized_form'));
        self::assertSame(['khá đặc biệt'], array_column($value['editorial_signals'], 'term'));
    }

    public function test_packet_reuses_dictionary_lexical_semantics_and_preserves_context(): void
    {
        $packet = (new StructuredSemanticInterpreter())->interpret(UniversalInputEnvelope::fromArray([
            'input_type' => 'TRANSCRIPT',
            'source_identity' => ['source_id' => 'video-1'],
            'raw_input_reference' => 'capture:1',
            'locale' => 'vi-VN',
            'text' => 'Cấu hình thử nghiệm có 17 alpha 19 beta và Ref 81.12.',
            'metadata' => [
                'lexical_hints' => [
                    ['kind' => 'STRUCTURAL_UNIT', 'term' => 'alpha'],
                    ['kind' => 'STRUCTURAL_UNIT', 'term' => 'beta'],
                ],
            ],
        ]));

        $value = $packet->toArray();
        self::assertSame('transcript', $value['source_context']['source_kind']);
        self::assertSame('vi-VN', $value['locale']);
        self::assertContains('17 alpha 19 beta', array_column($value['configuration_spans'], 'normalized_term'));
        self::assertContains('ref 81.12', array_column($value['identifier_spans'], 'normalized_term'));
        self::assertNotContains('19 beta', array_column($value['lexical_spans'], 'normalized_term'));
        self::assertNotEmpty($value['diagnostics']);
    }

    public function test_ambiguity_fails_closed_and_unknown_lexical_term_survives(): void
    {
        $packet = (new StructuredSemanticInterpreter())->interpret([
            'input_type' => 'KNOWLEDGE_TEXT',
            'text' => '“Alpha-Beta” được ghi nhận.',
            'subject_resolution' => [
                'status' => 'ambiguous',
                'candidates' => [
                    ['id' => 'one', 'type' => 'variant'],
                    ['id' => 'two', 'type' => 'variant'],
                ],
            ],
        ]);

        $value = $packet->toArray();
        self::assertSame('AMBIGUOUS', $value['status']);
        self::assertNotEmpty($value['ambiguous_terms']);
        self::assertContains('alpha-beta', array_column($value['unresolved_terms'], 'normalized_term'));
        self::assertSame([], $value['relation_candidates']);
    }

    public function test_lexical_evidence_gate_keeps_observations_without_sending_them_to_resolver(): void
    {
        $interpreter = new StructuredSemanticInterpreter();

        $observation = $interpreter->interpret(['raw_text' => 'carillon'])->toArray();
        self::assertSame('OBSERVATION_ONLY', $observation['lexical_spans'][0]['evidence_status']);
        self::assertFalse($observation['lexical_spans'][0]['resolver_eligible']);
        self::assertSame([], $observation['semantic_query_seeds']);
        self::assertSame([], $observation['dictionary_delta_candidates']);

        $named = $interpreter->interpret(['raw_text' => 'Khi nhìn một chiếc Atmos chạy.'])->toArray();
        self::assertContains('atmos', array_column($named['semantic_query_seeds'], 'normalized_form'));

        $qualified = $interpreter->interpret(['raw_text' => 'Bộ thoát hoạt động. Bộ thoát.'])->toArray();
        self::assertSame(['bộ thoát'], array_column($qualified['semantic_query_seeds'], 'normalized_form'));
        self::assertSame(2, $qualified['semantic_query_seeds'][0]['occurrences']);
        self::assertNotContains('bộ thoát hoạt động', array_column($qualified['lexical_spans'], 'normalized_term'));
    }

    public function test_observation_only_packet_retains_source_identity_and_lineage_without_dictionary_work(): void
    {
        $value = (new StructuredSemanticInterpreter())->interpret([
            'raw_text' => 'carillon',
            'source_kind' => 'ARTICLE',
            'source_identity' => ['source_id' => 'article:18', 'source_family' => 'ARTICLE'],
            'raw_or_derived' => 'RAW',
            'lineage' => ['source_family' => 'ARTICLE', 'editorial_context' => 'publish'],
        ])->toArray();

        self::assertSame('article:18', $value['source_context']['source_identifier']);
        self::assertSame(['source_family' => 'ARTICLE', 'editorial_context' => 'publish'], $value['source_context']['lineage']);
        self::assertSame([], $value['semantic_query_seeds']);
        self::assertSame([], $value['dictionary_delta_candidates']);
        self::assertSame('OBSERVATION_ONLY', $value['lexical_spans'][0]['evidence_status']);
    }

    public function test_noise_signals_do_not_reenter_the_qualified_lexical_path(): void
    {
        $value = (new StructuredSemanticInterpreter())->interpret([
            'raw_text' => 'Hiện vật mang đồng thời nhiều đặc điểm; bộ máy hoàn toàn nguyên bản.',
        ])->toArray();

        $spans = array_column($value['lexical_spans'], null, 'normalized_term');
        $seeds = array_column($value['semantic_query_seeds'], null, 'normalized_form');
        foreach (['hiện vật mang đồng thời nhiều đặc điểm', 'bộ máy hoàn toàn nguyên bản'] as $noise) {
            self::assertSame('NOISE', $spans[$noise]['evidence_status']);
            self::assertFalse($spans[$noise]['resolver_eligible']);
            self::assertFalse($seeds[$noise]['resolver_eligible']);
        }
    }

    public function test_qualified_two_pair_configuration_exposes_one_compact_lookup_variant(): void
    {
        $packet = (new StructuredSemanticInterpreter())->interpret([
            'text' => '36 ngày 10 tháng',
            'source_kind' => 'human_chat',
            'metadata' => ['lexical_hints' => [['kind' => 'STRUCTURAL_UNIT', 'term' => 'ngày'], ['kind' => 'STRUCTURAL_UNIT', 'term' => 'tháng']]],
        ])->toArray();

        $configuration = array_values(array_filter(
            $packet['semantic_query_seeds'],
            static fn (array $seed): bool => ($seed['category'] ?? '') === 'CONFIGURATION',
        ));

        self::assertCount(1, $configuration);
        self::assertSame(['36/10'], $configuration[0]['lookup_variants']);
        self::assertSame('36 ngày 10 tháng', $configuration[0]['normalized_form']);
        self::assertTrue($configuration[0]['resolver_eligible']);
    }

    public function test_unequal_multi_pair_configuration_does_not_expose_reduction_variant(): void
    {
        $packet = (new StructuredSemanticInterpreter())->interpret([
            'text' => '36 ngày 10 tháng 2 năm',
            'source_kind' => 'human_chat',
            'metadata' => ['lexical_hints' => [['kind' => 'STRUCTURAL_UNIT', 'term' => 'ngày'], ['kind' => 'STRUCTURAL_UNIT', 'term' => 'tháng'], ['kind' => 'STRUCTURAL_UNIT', 'term' => 'năm']]],
        ])->toArray();

        foreach ($packet['semantic_query_seeds'] as $seed) {
            if (($seed['category'] ?? '') === 'CONFIGURATION') self::assertSame([], $seed['lookup_variants']);
        }
    }

    public function test_explicit_relation_hints_are_planned_only_and_unregistered_predicates_fail_closed(): void
    {
        $packet = (new StructuredSemanticInterpreter())->interpret([
            'input_type' => 'HUMAN_HINT',
            'text' => 'Alpha và Beta.',
            'relation_hints' => [
                ['source_id' => 'a', 'target_id' => 'b', 'predicate' => 'invented_predicate'],
            ],
        ]);

        $value = $packet->toArray();
        self::assertSame([], $value['relation_candidates']);
        self::assertContains('RELATION_PREDICATE_UNSUPPORTED', $value['diagnostics']);
    }

    public function test_derived_lineage_is_not_independent_corroboration(): void
    {
        $guard = new DerivedLineageGuard();

        self::assertFalse($guard->isIndependent([
            'source_kind' => 'ARTICLE',
            'lineage' => ['parent_claim_ids' => ['claim-a'], 'source_family' => 'claim-a'],
        ]));
        self::assertTrue($guard->isIndependent([
            'source_kind' => 'CATALOG',
            'lineage' => ['source_family' => 'catalog:42'],
        ]));
    }

    public function test_legacy_capture_adapter_and_transcript_media_inputs_share_the_same_lexical_packet(): void
    {
        $text = 'Cấu hình thử nghiệm có 17 alpha 19 beta.';
        $direct = (new StructuredSemanticInterpreter())->interpret(['input_type' => 'VIDEO_TEXT', 'text' => $text, 'metadata' => ['lexical_hints' => [['kind' => 'STRUCTURAL_UNIT', 'term' => 'alpha'], ['kind' => 'STRUCTURAL_UNIT', 'term' => 'beta']]]])->toArray();
        $legacy = (new TextInputInterpreter())->interpret($text, [], [], ['source_kind' => 'TRANSCRIPT', 'lexical_hints' => [['kind' => 'STRUCTURAL_UNIT', 'term' => 'alpha'], ['kind' => 'STRUCTURAL_UNIT', 'term' => 'beta']]]);

        self::assertSame(array_column($direct['configuration_spans'], 'normalized_term'), array_column($legacy['structured_interpretation_packet']['configuration_spans'], 'normalized_term'));
        self::assertSame('video_text', $direct['source_context']['source_kind']);
        self::assertSame('transcript', $legacy['structured_interpretation_packet']['source_context']['source_kind']);
    }

    public function test_input_contract_preserves_lineage_intent_target_provenance_and_observation_strength(): void
    {
        $packet = (new StructuredSemanticInterpreter())->interpret([
            'text' => 'Unknown valid term được quan sát.',
            'locale' => 'vi-VN',
            'source_kind' => 'human_chat',
            'source_identifier' => 'chat:42',
            'raw_input_reference' => 'capture:42',
            'raw_or_derived' => 'RAW',
            'content_intent_context' => ['intent' => 'KNOWLEDGE_DELTA'],
            'canonical_target_hint' => ['type' => 'model', 'id' => 'model-1'],
            'provenance_context' => ['source_class' => 'EXPLICIT_USER_KNOWLEDGE'],
            'observation_strength' => 'NORMAL',
            'hints' => ['Unknown valid term'],
        ])->toArray();

        self::assertSame('human_chat', $packet['source_context']['source_kind']);
        self::assertSame('chat:42', $packet['source_context']['source_identifier']);
        self::assertSame('RAW', $packet['source_context']['raw_or_derived']);
        self::assertSame(['intent' => 'KNOWLEDGE_DELTA'], $packet['source_context']['content_intent_context']);
        self::assertSame(['type' => 'model', 'id' => 'model-1'], $packet['source_context']['canonical_target_hint']);
        self::assertSame(['source_class' => 'EXPLICIT_USER_KNOWLEDGE'], $packet['source_context']['provenance_context']);
        self::assertSame('NORMAL', $packet['source_context']['observation_strength']);
        self::assertSame('unknown valid term', $packet['semantic_query_seeds'][0]['normalized_form']);
    }
}
