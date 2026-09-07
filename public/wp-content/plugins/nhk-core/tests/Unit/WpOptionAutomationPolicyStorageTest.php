<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use InvalidArgumentException;
use NHK\Core\Infrastructure\Governance\WpOptionAutomationPolicyStorage;
use PHPUnit\Framework\TestCase;

final class WpOptionAutomationPolicyStorageTest extends TestCase
{
    public function test_missing_option_reads_as_empty(): void
    {
        $storage = new WpOptionAutomationPolicyStorage(['video', 'media'], 'policy', static fn (string $name, array $default): array => $default);

        self::assertSame([], $storage->read());
    }

    public function test_valid_policy_map_round_trips_through_option_callbacks(): void
    {
        $stored = [];
        $storage = new WpOptionAutomationPolicyStorage(
            ['video', 'media'],
            'policy',
            static function (string $name, array $default) use (&$stored): array { return $stored ?: $default; },
            static function (string $name, array $value) use (&$stored): bool { $stored = $value; return true; },
        );

        $storage->write(['video' => 'AUTO_PUBLISH', 'media' => 'REVIEW_REQUIRED']);

        self::assertSame(['video' => 'AUTO_PUBLISH', 'media' => 'REVIEW_REQUIRED'], $storage->read());
    }

    public function test_invalid_mode_or_unknown_type_is_rejected_before_write(): void
    {
        $writes = 0;
        $storage = new WpOptionAutomationPolicyStorage(
            ['video'],
            'policy',
            static fn (string $name, array $default): array => $default,
            static function () use (&$writes): bool { $writes++; return true; },
        );

        $this->expectException(InvalidArgumentException::class);
        $storage->write(['note' => 'AUTO_APPROVE']);
        self::assertSame(0, $writes);
    }
}
