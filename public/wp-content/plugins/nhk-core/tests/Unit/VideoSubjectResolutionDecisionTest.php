<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoSubjectResolutionDecision;
use PHPUnit\Framework\TestCase;

final class VideoSubjectResolutionDecisionTest extends TestCase
{
    public function test_facets_and_observations_auto_resolve_clear_candidate(): void
    {
        $result = VideoSubjectResolutionDecision::resolve([
            'name' => 'Chronograph', 'parent' => 'Brand A', 'configuration_facets' => ['blue dial'],
            'music' => 'jazz', 'movement' => 'calibre-x', 'observations' => ['blue dial'],
        ], [
            ['id' => 'a', 'type' => 'variant', 'name' => 'Chronograph', 'aliases' => ['Chronograph Blue'], 'parent' => 'Brand A', 'configuration_facets' => ['blue dial'], 'music' => ['jazz'], 'movement' => ['calibre-x']],
            ['id' => 'b', 'type' => 'variant', 'name' => 'Chronograph', 'aliases' => [], 'parent' => 'Brand A', 'configuration_facets' => ['black dial'], 'music' => ['rock'], 'movement' => ['calibre-y']],
        ]);

        self::assertSame('AUTO_RESOLVE', $result['decision']);
        self::assertSame('a', $result['selected']['id']);
        self::assertNotEmpty($result['trace']);
    }

    public function test_true_tie_requires_human_review_after_discrimination(): void
    {
        $candidates = [
            ['id' => 'a', 'type' => 'model', 'name' => 'Same', 'aliases' => ['shared']],
            ['id' => 'b', 'type' => 'model', 'name' => 'Same', 'aliases' => ['shared']],
        ];
        $result = VideoSubjectResolutionDecision::resolve(['name' => 'shared'], $candidates);
        self::assertSame('HUMAN_REVIEW', $result['decision']);
        self::assertNull($result['selected']);
    }

    public function test_incompatible_family_is_not_silently_auto_resolved(): void
    {
        $result = VideoSubjectResolutionDecision::resolve(['name' => 'same'], [
            ['id' => 'a', 'type' => 'variant', 'name' => 'same', 'family' => 'family-a'],
            ['id' => 'b', 'type' => 'variant', 'name' => 'same', 'family' => 'family-b'],
        ]);
        self::assertSame('HUMAN_REVIEW', $result['decision']);
        self::assertNotEmpty($result['conflicts']);
    }

    public function test_empty_candidates_are_hard_blocked_only_for_identity(): void
    {
        $result = VideoSubjectResolutionDecision::resolve(['name' => 'unknown'], []);
        self::assertSame('HARD_BLOCK', $result['decision']);
        self::assertSame('CANONICAL_IDENTITY_UNRESOLVED', $result['reason']);
    }
}
