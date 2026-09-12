<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ChatGptFileAllowlistConfigTest extends TestCase
{
    public function test_staging_mu_plugin_registers_only_the_observed_exact_host(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 4) . '/mu-plugins/nhk-chatgpt-file-transport.php');

        self::assertStringContainsString("add_filter('nhk_chatgpt_file_allowed_hosts'", $config);
        self::assertStringContainsString("!function_exists('wp_get_environment_type') || wp_get_environment_type() !== 'staging'", $config);
        self::assertStringContainsString('sdmntpraustraliaeast.oaiusercontent.com', $config);
        self::assertStringNotContainsString('*.oaiusercontent.com', $config);
        self::assertStringNotContainsString("'oaiusercontent.com'", $config);
    }
}
