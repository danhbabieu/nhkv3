<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoEditorialDecisionPipeline;
use PHPUnit\Framework\TestCase;

final class VideoEditorialDecisionPipelineTest extends TestCase
{
    public function test_repairable_finding_is_repaired_then_ready_with_trace(): void
    {
        $critiqueRounds = [];
        $result = (new VideoEditorialDecisionPipeline())->run(
            ['title' => 'Mọi chiếc đều tốt nhất', 'body' => 'Nội dung ban đầu.', 'claims' => []],
            [],
            static fn (array $package): array => $package,
            static function (array $package, array $context, int $round) use (&$critiqueRounds): array {
                $critiqueRounds[] = $round;
                return $round === 0 ? [[
                    'code' => 'UNSUPPORTED_EXPANSION', 'severity' => 'REPAIRABLE', 'scope' => 'claim', 'claim_id' => 'claim-1', 'repair' => 'REMOVE_UNSUPPORTED', 'reason' => 'Remove unsupported sentence.',
                ]] : [];
            },
        );

        self::assertSame('READY', $result['quality']);
        self::assertSame([0, 1], $critiqueRounds);
        self::assertSame(1, $result['rounds']);
        self::assertNotEmpty($result['trace']);
    }

    public function test_secondary_visual_support_can_be_repaired_but_core_support_hard_blocks(): void
    {
        $pipeline = new VideoEditorialDecisionPipeline();
        $secondary = $pipeline->run(['title' => 'Video', 'body' => 'Body'], [], static fn (array $package): array => $package, static fn (): array => [[
            'code' => 'VISUAL_SUPPORT_SECONDARY', 'severity' => 'REPAIRABLE', 'scope' => 'claim', 'claim_id' => 'secondary', 'repair' => 'REMOVE_UNSUPPORTED', 'reason' => 'Optional detail can be removed.',
        ]]);
        $core = $pipeline->run(['title' => 'Video', 'body' => 'Body'], [], static fn (array $package): array => $package, static fn (): array => [[
            'code' => 'VISUAL_SUPPORT_CORE', 'severity' => 'HARD_BLOCK', 'scope' => 'claim', 'claim_id' => 'core', 'repair' => 'REDUCE_SPECIFICITY', 'reason' => 'Core claim cannot be supported.',
        ]]);

        self::assertSame('READY', $secondary['quality']);
        self::assertSame('HARD_BLOCK', $core['quality']);
    }

    public function test_review_required_survives_bounded_repair_rounds(): void
    {
        $result = (new VideoEditorialDecisionPipeline())->run(['title' => 'Video', 'body' => 'Body'], [], static fn (array $package): array => $package, static fn (): array => [[
            'code' => 'FACTUAL_CONFLICT', 'severity' => 'REVIEW_REQUIRED', 'scope' => 'claim', 'claim_id' => 'core', 'repair' => 'PREFER_CANONICAL', 'reason' => 'Core conflict requires review.',
        ]]);
        self::assertSame('REVIEW_REQUIRED', $result['quality']);
        self::assertLessThanOrEqual(3, $result['rounds']);
    }
}
