<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\LegacyRelationPlanner;
use PHPUnit\Framework\TestCase;

final class LegacyRelationPlannerTest extends TestCase
{
    public function test_missing_legacy_model_parent_is_relation_pending_not_not_applicable(): void
    {
        $result = (new LegacyRelationPlanner())->resolve([
            'type' => 'model',
            'uuid' => 'model-1',
            'stable_key' => 'nhk:model:odo.24',
            'provenance' => [],
        ]);

        self::assertSame('RELATION_PENDING', $result['status']);
        self::assertSame('model_of', $result['expected_relation']['predicate']);
        self::assertSame('brand', $result['expected_relation']['target_type']);
        self::assertSame('MISSING_RELATION_METADATA', $result['reason']);
    }

    public function test_structured_legacy_parent_becomes_deterministic_candidate(): void
    {
        $result = (new LegacyRelationPlanner())->resolve([
            'type' => 'variant',
            'uuid' => 'variant-1',
            'stable_key' => 'nhk:variant:odo.24.54',
            'provenance' => ['model_uuid' => 'model-1'],
        ]);

        self::assertSame('MISSING_DETERMINISTIC', $result['status']);
        self::assertSame('variant_of', $result['candidate']['predicate']);
        self::assertSame('model-1', $result['candidate']['targetUuid']);
    }

    public function test_legacy_classification_is_explicit_registry_gap_and_media_is_not_applicable(): void
    {
        $planner = new LegacyRelationPlanner();

        self::assertSame('REGISTRY_GAP', $planner->resolve([
            'type' => 'classification', 'uuid' => 'classification-1', 'stable_key' => 'nhk:classification:clock-type.cuckoo-clock', 'provenance' => [],
        ])['status']);
        self::assertSame('NOT_APPLICABLE', $planner->resolve([
            'type' => 'media', 'uuid' => 'media-1', 'stable_key' => 'v2:media:1', 'provenance' => [],
        ])['status']);
    }

    public function test_legacy_knowledge_and_video_without_subject_hints_are_pending(): void
    {
        $planner = new LegacyRelationPlanner();

        self::assertSame('RELATION_PENDING', $planner->resolve([
            'type' => 'knowledge', 'uuid' => 'knowledge-1', 'stable_key' => 'v2:knowledge:odo', 'provenance' => [],
        ])['status']);
        self::assertSame('about', $planner->resolve([
            'type' => 'video', 'uuid' => 'video-1', 'provenance' => [],
        ])['expected_relation']['predicate']);
    }
}
