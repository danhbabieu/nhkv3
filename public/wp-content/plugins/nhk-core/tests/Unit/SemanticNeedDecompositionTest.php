<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{SemanticInputEnvelope, SemanticNeed, SemanticNeedRetrievalPolicy};
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
}
