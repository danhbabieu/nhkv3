<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\VideoEditorialDecisionPipeline;
use NHK\Core\Application\Compliance\PublicEditorialCopyGuard;
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
        $secondary = $pipeline->run(['title' => 'Video', 'body' => 'Body'], [], static fn (array $package): array => $package, static fn (array $package): array => $package['repair_log'] ?? [] ? [] : [[
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

    public function test_repairable_public_jargon_is_rewritten_and_critique_runs_again(): void
    {
        $rounds = [];
        $guard = new PublicEditorialCopyGuard();
        $result = (new VideoEditorialDecisionPipeline())->run(
            [
                'title' => 'Đồng hồ công cộng',
                'summary' => 'Video này ghi lại một hiện vật qua nguồn tham chiếu cụ thể.',
                'body' => 'Trong bối cảnh tri thức NHK, chủ thể liên quan được nhận diện là đồng hồ công cộng. Bộ máy và mặt số vẫn được giữ nguyên.',
                'seo_description' => 'Đồng hồ công cộng qua một nguồn tham chiếu cụ thể.',
                'claims' => [],
            ],
            [],
            static fn (array $package): array => $package,
            static function (array $package, array $context, int $round) use (&$rounds, $guard): array {
                $rounds[] = $round;
                $findings = $guard->findings($package);
                return array_map(static fn (array $finding): array => [
                    'code' => $finding['code'],
                    'severity' => $finding['severity'],
                    'scope' => 'artifact',
                    'claim_id' => null,
                    'repair' => $finding['repair'],
                    'reason' => $finding['reason'],
                    'field' => $finding['field'],
                ], $findings);
            },
        );

        self::assertSame('READY', $result['quality']);
        self::assertSame([0, 1], $rounds);
        self::assertSame(1, $result['rounds']);
        self::assertStringNotContainsString('tri thức NHK', $result['editorial_package']['body']);
        self::assertStringContainsString('Bộ máy và mặt số', $result['editorial_package']['body']);
        self::assertNotEmpty($result['editorial_package']['repair_log']);
    }

    public function test_structural_public_leak_remains_hard_blocked(): void
    {
        $guard = new PublicEditorialCopyGuard();
        $result = (new VideoEditorialDecisionPipeline())->run(
            ['title' => 'Đồng hồ công cộng', 'body' => 'Bản ghi 01a09e44-539a-7f1a-938a-d7d91bb689a3.', 'claims' => []],
            [],
            static fn (array $package): array => $package,
            static fn (array $package) => array_map(static fn (array $finding): array => [
                'code' => $finding['code'], 'severity' => $finding['severity'], 'scope' => 'artifact',
                'claim_id' => null, 'repair' => $finding['repair'], 'reason' => $finding['reason'],
            ], $guard->findings($package)),
        );

        self::assertSame('HARD_BLOCK', $result['quality']);
        self::assertSame([], $result['editorial_package']['repair_log'] ?? []);
    }

    public function test_repair_revalidates_and_updates_seo_projection_from_the_repaired_package(): void
    {
        $guard = new PublicEditorialCopyGuard();
        $result = (new VideoEditorialDecisionPipeline())->run(
            [
                'title' => 'Video về một hiện vật',
                'body' => 'Trong bối cảnh tri thức NHK, nội dung mô tả hiện vật.',
                'seo_description' => 'Trong bối cảnh tri thức NHK, mô tả ngắn cho người đọc.',
                'claims' => [],
            ],
            [],
            static fn (array $package): array => $package,
            static fn (array $package) => array_map(static fn (array $finding): array => [
                'code' => $finding['code'], 'severity' => $finding['severity'], 'scope' => 'artifact',
                'claim_id' => null, 'repair' => $finding['repair'], 'reason' => $finding['reason'],
                'field' => $finding['field'],
            ], $guard->findings($package)),
        );

        self::assertSame('READY', $result['quality']);
        self::assertStringNotContainsString('tri thức NHK', $result['editorial_package']['body']);
        self::assertStringNotContainsString('tri thức NHK', $result['editorial_package']['seo_description']);
        self::assertSame([0, 1], array_column($result['trace'], 'round'));
    }
}
