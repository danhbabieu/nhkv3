<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Video\YouTubeApiConfiguration;
use PHPUnit\Framework\TestCase;

final class YouTubeApiConfigurationTest extends TestCase
{
    public function test_constant_key_is_selected_before_environment_key(): void
    {
        $configuration = new YouTubeApiConfiguration(
            static fn (): ?string => 'constant-secret',
            static fn (): ?string => 'environment-secret',
        );

        self::assertSame(['configured' => true, 'source' => 'constant'], $configuration->diagnostic());
        self::assertSame('constant-secret', $configuration->value());
    }

    public function test_environment_key_is_selected_when_constant_is_absent(): void
    {
        $configuration = new YouTubeApiConfiguration(
            static fn (): ?string => null,
            static fn (): ?string => 'environment-secret',
        );

        self::assertSame(['configured' => true, 'source' => 'environment'], $configuration->diagnostic());
        self::assertSame('environment-secret', $configuration->value());
    }

    public function test_missing_key_is_reported_without_exposing_a_value(): void
    {
        $configuration = new YouTubeApiConfiguration(
            static fn (): ?string => null,
            static fn (): ?string => null,
        );

        self::assertSame(['configured' => false, 'source' => 'none'], $configuration->diagnostic());
        self::assertNull($configuration->value());
        self::assertStringNotContainsString('secret', json_encode($configuration->diagnostic(), JSON_THROW_ON_ERROR));
    }
}
