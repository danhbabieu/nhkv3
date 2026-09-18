<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use InvalidArgumentException;
use NHK\Core\Application\Home\HomeHeroMediaConfig;
use PHPUnit\Framework\TestCase;

final class HomeHeroMediaConfigTest extends TestCase
{
    public function test_normalizes_ids_preserving_manual_order(): void
    {
        $config = new HomeHeroMediaConfig();

        self::assertSame(
            ['media-c', 'media-a'],
            $config->validate([' media-c ', 'media-c', 'media-a'], $this->candidates())
        );
    }

    public function test_rejects_more_than_five_media(): void
    {
        $config = new HomeHeroMediaConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tối đa 5');
        $config->validate(['a', 'b', 'c', 'd', 'e', 'f'], $this->candidates('a', 'b', 'c', 'd', 'e', 'f'));
    }

    public function test_rejects_missing_or_unavailable_or_non_image_media(): void
    {
        $config = new HomeHeroMediaConfig();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Không thể chọn Media');
        $config->validate(['missing'], $this->candidates());
    }

    public function test_rejects_candidate_without_public_image_projection(): void
    {
        $config = new HomeHeroMediaConfig();

        $this->expectException(InvalidArgumentException::class);
        $config->validate(['private'], [['id' => 'private', 'image_url' => '', 'has_real_image' => false]]);
    }

    public function test_status_reports_manual_and_fallback_counts(): void
    {
        $config = new HomeHeroMediaConfig();

        self::assertSame(
            ['manual' => 3, 'auto_fallback' => 2, 'total' => 5],
            $config->status(['a', 'b', 'c'], 5)
        );
    }

    /** @return list<array<string,mixed>> */
    private function candidates(string ...$ids): array
    {
        $ids = $ids ?: ['media-a', 'media-b', 'media-c'];
        return array_map(static fn (string $id): array => [
            'id' => $id,
            'image_url' => '/' . $id . '.webp',
            'has_real_image' => true,
        ], $ids);
    }
}
