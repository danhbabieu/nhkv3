<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{SemanticInputEnvelope, SemanticNeed, SemanticNeedDecomposer, SemanticNeedRetrievalPolicy, TextInputInterpreter};
use PHPUnit\Framework\TestCase;

final class SemanticNeedDecompositionTest extends TestCase
{
    public function test_input_envelope_round_trips_component_origins_without_promoting_observations(): void
    {
        $envelope = SemanticInputEnvelope::fromArray([
            'input_type' => 'video',
            'raw_text' => 'mô tả nguồn',
            'title' => 'Tiêu đề nguồn',
            'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'variant', 'revision' => 3]],
            'observations' => [['text' => 'quan sát mặt số', 'origin' => 'SPECIMEN_OBSERVATION']],
            'metadata' => ['source' => ['origin' => 'SOURCE_EXPLICIT']],
            'target_surface' => 'video',
            'components' => [
                ['key' => 'raw_text', 'origin' => 'USER_EXPLICIT'],
                ['key' => 'machine_hint', 'value' => 'shape', 'origin' => 'MACHINE_DERIVED'],
            ],
        ]);

        $value = $envelope->toArray();

        self::assertSame('video', $value['input_type']);
        self::assertSame('SPECIMEN_OBSERVATION', $value['observations'][0]['origin']);
        self::assertSame('MACHINE_DERIVED', $value['components'][1]['origin']);
        self::assertSame('SOURCE_EXPLICIT', $value['metadata']['source']['origin']);
    }

    public function test_semantic_need_id_is_deterministic_and_keeps_authoritative_subject(): void
    {
        $data = [
            'canonical_subject' => ['id' => 'subject-1', 'type' => 'variant', 'revision' => 3],
            'concept_key' => 'dial_form',
            'facet_key' => 'form',
            'scope' => 'variant',
            'intent' => 'recognition',
            'origin' => 'USER_EXPLICIT',
            'confidence' => 0.8,
            'evidence_requirement' => 'SUPPORTED_WITHIN_SCOPE',
        ];

        $left = SemanticNeed::fromArray($data);
        $right = SemanticNeed::fromArray($data);

        self::assertSame($left->needId(), $right->needId());
        self::assertSame('subject-1', $left->canonicalSubject()['id']);
        self::assertSame('variant', $left->canonicalSubject()['type']);
        self::assertSame('dial_form', $left->conceptKey());
        self::assertSame('form', $left->facetKey());
    }

    public function test_semantic_need_rejects_missing_subject_or_empty_concept_and_facet(): void
    {
        $base = [
            'canonical_subject' => ['id' => 'subject-1', 'type' => 'variant'],
            'concept_key' => 'dial_form',
            'facet_key' => 'form',
            'scope' => 'variant',
            'intent' => 'recognition',
            'origin' => 'USER_EXPLICIT',
            'confidence' => 0.8,
            'evidence_requirement' => 'SUPPORTED_WITHIN_SCOPE',
        ];

        $this->expectException(\InvalidArgumentException::class);
        SemanticNeed::fromArray(array_replace($base, ['canonical_subject' => []]));
    }

    public function test_semantic_need_rejects_invalid_confidence_and_unknown_relaxation_tier(): void
    {
        $base = [
            'canonical_subject' => ['id' => 'subject-1', 'type' => 'variant'],
            'concept_key' => 'dial_form',
            'facet_key' => 'form',
            'scope' => 'variant',
            'intent' => 'recognition',
            'origin' => 'MACHINE_DERIVED',
            'confidence' => 1.2,
            'evidence_requirement' => 'SUPPORTED_WITHIN_SCOPE',
        ];

        $this->expectException(\InvalidArgumentException::class);
        SemanticNeed::fromArray($base);

    }

    public function test_semantic_need_rejects_unknown_relaxation_tier(): void
    {
        $base = [
            'canonical_subject' => ['id' => 'subject-1', 'type' => 'variant'],
            'concept_key' => 'dial_form',
            'facet_key' => 'form',
            'scope' => 'variant',
            'intent' => 'recognition',
            'origin' => 'MACHINE_DERIVED',
            'confidence' => 0.4,
            'evidence_requirement' => 'SUPPORTED_WITHIN_SCOPE',
        ];

        $this->expectException(\InvalidArgumentException::class);
        SemanticNeed::fromArray($base + ['relaxation_policy' => ['tiers' => ['UNSAFE_DOMAIN_GUESS']]]);
    }

    public function test_policy_is_bounded_and_omits_unregistered_tiers(): void
    {
        $policy = SemanticNeedRetrievalPolicy::defaults(['EXACT', 'SUBJECT_BROADENED']);

        self::assertSame(['EXACT', 'SUBJECT_BROADENED'], $policy['tiers']);
        self::assertLessThanOrEqual(200, $policy['opportunity_budget']);
        self::assertLessThanOrEqual(200, $policy['expansion_budget']);
        self::assertNotContains('UNSAFE_DOMAIN_GUESS', $policy['tiers']);
    }

    public function test_decomposer_emits_independent_needs_and_deduplicates_equivalent_components(): void
    {
        $envelope = SemanticInputEnvelope::fromArray([
            'raw_text' => 'năm khái quát',
            'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'variant', 'revision' => 3]],
            'components' => [
                ['facet_key' => 'dial', 'concept_key' => 'form', 'scope' => 'variant', 'origin' => 'USER_EXPLICIT'],
                ['facet_key' => 'case', 'concept_key' => 'style', 'scope' => 'variant', 'origin' => 'USER_EXPLICIT'],
                ['facet_key' => 'movement', 'concept_key' => 'mechanism', 'scope' => 'variant', 'origin' => 'SOURCE_EXPLICIT'],
                ['facet_key' => 'finish', 'concept_key' => 'treatment', 'scope' => 'variant', 'origin' => 'MACHINE_DERIVED'],
                ['facet_key' => 'configuration', 'concept_key' => 'layout', 'scope' => 'variant', 'origin' => 'USER_EXPLICIT'],
                ['facet_key' => 'dial', 'concept_key' => 'form', 'scope' => 'variant', 'origin' => 'USER_EXPLICIT'],
            ],
        ]);

        $result = (new SemanticNeedDecomposer(new TextInputInterpreter(), $this->vocabulary()))->decompose($envelope);
        $needs = $result->toArray()['needs'];

        self::assertCount(5, $needs);
        self::assertSame(['dial', 'case', 'movement', 'finish', 'configuration'], array_column($needs, 'facet_key'));
        self::assertSame(['subject-1', 'subject-1', 'subject-1', 'subject-1', 'subject-1'], array_column(array_column($needs, 'canonical_subject'), 'id'));
        self::assertContains('DUPLICATE_NEED_COLLAPSED', $result->toArray()['diagnostics']);
    }

    public function test_decomposer_preserves_authoritative_subject_and_specimen_origin(): void
    {
        $envelope = SemanticInputEnvelope::fromArray([
            'subject_resolution' => [
                'primary' => ['id' => 'authoritative-1', 'type' => 'variant', 'revision' => 7],
                'candidates' => [['id' => 'weaker-1', 'type' => 'model']],
            ],
            'subject_hints' => ['weaker-1'],
            'observations' => [['text' => 'observed feature', 'facet_key' => 'appearance', 'concept_key' => 'surface', 'scope' => 'specimen', 'origin' => 'SPECIMEN_OBSERVATION']],
        ]);

        $needs = (new SemanticNeedDecomposer(new TextInputInterpreter(), $this->vocabulary()))->decompose($envelope)->toArray()['needs'];

        self::assertCount(1, $needs);
        self::assertSame('authoritative-1', $needs[0]['canonical_subject']['id']);
        self::assertSame('variant', $needs[0]['canonical_subject']['type']);
        self::assertSame('specimen', $needs[0]['scope']);
        self::assertSame('SPECIMEN_OBSERVATION', $needs[0]['origin']);
    }

    public function test_decomposer_keeps_unknown_lexical_candidate_unresolved(): void
    {
        $envelope = SemanticInputEnvelope::fromArray([
            'raw_text' => 'từ khóa chưa đăng ký',
            'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'variant']],
            'components' => [['facet_key' => 'unknown-facet', 'concept_key' => 'unknown-concept', 'origin' => 'MACHINE_DERIVED']],
        ]);

        $result = (new SemanticNeedDecomposer(new TextInputInterpreter(), $this->vocabulary()))->decompose($envelope)->toArray();

        self::assertSame([], $result['needs']);
        self::assertNotEmpty($result['unresolved']);
        self::assertContains('UNREGISTERED_SEMANTIC_PRIMITIVE', array_column($result['unresolved'], 'reason'));
    }

    private function vocabulary(): object
    {
        return new class {
            public function isRegistered(string $facet, string $concept): bool
            {
                return in_array($facet . ':' . $concept, [
                    'dial:form', 'case:style', 'movement:mechanism', 'finish:treatment',
                    'configuration:layout', 'appearance:surface',
                ], true);
            }
        };
    }
}
