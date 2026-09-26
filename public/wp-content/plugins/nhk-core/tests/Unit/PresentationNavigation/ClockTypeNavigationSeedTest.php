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

    public function test_seed_blocks_missing_and_ambiguous_targets_but_accepts_origin_without_creating_semantic_records(): void
    {
        $repository = new InMemoryNavigationRepository();
        $resolver = static function (string $label): array {
            if ($label === 'Đồng hồ tủ') return [];
            if ($label === 'Đồng hồ treo tường') return [['canonical_uuid' => 'wrong', 'canonical_type' => 'classification', 'family' => 'origin', 'active' => true]];
            if ($label === 'Đồng hồ Pháp') return [['canonical_uuid' => 'a', 'canonical_type' => 'classification', 'family' => 'clock_type', 'active' => true], ['canonical_uuid' => 'b', 'canonical_type' => 'classification', 'family' => 'clock_type', 'active' => true]];
            return [['canonical_uuid' => 'uuid-' . md5($label), 'canonical_type' => 'classification', 'family' => 'clock_type', 'active' => true]];
        };
        $reports = (new ClockTypeNavigationSeed($repository, $resolver))->seed();

        self::assertSame(['REVIEW_REQUIRED', 'SEEDED', 'BLOCKED'], array_column(array_slice($reports, 0, 3), 'status'));
        self::assertCount(7, $repository->list(ClockTypeNavigationProjection::NAVIGATION_KEY));
    }

    public function test_seed_accepts_curated_origin_classifications_without_creating_them(): void
    {
        $repository = new InMemoryNavigationRepository();
        $resolver = static fn (string $label): array => [[
            'canonical_uuid' => 'canonical-' . md5($label),
            'canonical_type' => 'classification',
            'family' => in_array($label, ['Đồng hồ Pháp', 'Đồng hồ Đức'], true) ? 'origin' : 'clock_type',
            'active' => true,
        ]];

        $reports = (new ClockTypeNavigationSeed($repository, $resolver))->seed();

        self::assertSame('SEEDED', $reports[2]['status']);
        self::assertSame('canonical-' . md5('Đồng hồ Pháp'), $reports[2]['canonical_uuid']);
        self::assertSame('SEEDED', $reports[3]['status']);
        self::assertSame('canonical-' . md5('Đồng hồ Đức'), $reports[3]['canonical_uuid']);
        self::assertCount(9, $repository->list(ClockTypeNavigationProjection::NAVIGATION_KEY));
    }
}
