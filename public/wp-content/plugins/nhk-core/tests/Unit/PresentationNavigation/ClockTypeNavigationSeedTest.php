<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\PresentationNavigation;

use NHK\Core\Application\Presentation\{ClockTypeNavigationProjection, ClockTypeNavigationSeed, NavigationTreeProjector};
use NHK\Core\Infrastructure\Presentation\InMemoryNavigationRepository;
use PHPUnit\Framework\TestCase;

final class ClockTypeNavigationSeedTest extends TestCase
{
    public function test_seed_resolves_existing_exact_family_and_replay_is_idempotent(): void
    {
        $repository = new InMemoryNavigationRepository();
        $resolver = static fn (string $label): array => [['canonical_uuid' => 'uuid-' . md5($label), 'canonical_type' => 'classification', 'family' => 'clock_type', 'active' => true]];
        $seed = new ClockTypeNavigationSeed($repository, $resolver);

        $first = $seed->seed();
        $repository->save($repository->list('clock_type')[0], 1);
        $second = $seed->seed();

        self::assertCount(9, $first);
        self::assertSame(9, count($repository->list('clock_type')));
        self::assertSame('ALREADY_PRESENT', $second[0]['status']);
    }

    public function test_seed_blocks_missing_wrong_family_and_ambiguous_targets_without_creating_semantic_records(): void
    {
        $repository = new InMemoryNavigationRepository();
        $resolver = static function (string $label): array {
            if ($label === 'Đồng hồ tủ') return [];
            if ($label === 'Đồng hồ treo tường') return [['canonical_uuid' => 'wrong', 'canonical_type' => 'classification', 'family' => 'origin', 'active' => true]];
            if ($label === 'Đồng hồ Pháp') return [['canonical_uuid' => 'a', 'canonical_type' => 'classification', 'family' => 'clock_type', 'active' => true], ['canonical_uuid' => 'b', 'canonical_type' => 'classification', 'family' => 'clock_type', 'active' => true]];
            return [['canonical_uuid' => 'uuid-' . md5($label), 'canonical_type' => 'classification', 'family' => 'clock_type', 'active' => true]];
        };
        $reports = (new ClockTypeNavigationSeed($repository, $resolver))->seed();

        self::assertSame(['REVIEW_REQUIRED', 'BLOCKED', 'BLOCKED'], array_column(array_slice($reports, 0, 3), 'status'));
        self::assertCount(6, $repository->list(ClockTypeNavigationProjection::NAVIGATION_KEY));
    }
}
