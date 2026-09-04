<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Migration\CanaryPublicIdentityProjection;
use PHPUnit\Framework\TestCase;

final class CanaryPublicIdentityProjectionTest extends TestCase
{
    public function test_pre_cutover_inspection_is_read_only_and_validates_canary_invariants(): void
    {
        $result = (new CanaryPublicIdentityProjection())->inspect();

        self::assertSame('PRE_CUTOVER_READY', $result['status']);
        self::assertSame(0, $result['mutation_count']);
        self::assertFalse($result['live_projection_performed']);
        self::assertNotContains(false, $result['checks']);
    }

    public function test_conflicting_readback_fails_closed_without_mutation(): void
    {
        $result = (new CanaryPublicIdentityProjection())->inspect([
            'video_uuid' => '11111111-1111-4111-8111-111111111111',
            'duplicate_video_count' => 1,
        ]);

        self::assertSame('BLOCKED', $result['status']);
        self::assertSame(0, $result['mutation_count']);
        self::assertFalse($result['checks']['video_uuid_preserved']);
        self::assertFalse($result['checks']['no_duplicate_video']);
    }
}
