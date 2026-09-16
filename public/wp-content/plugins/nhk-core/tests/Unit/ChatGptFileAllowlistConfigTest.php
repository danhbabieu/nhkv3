<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ChatGptFileAllowlistConfigTest extends TestCase
{
    public function test_staging_does_not_enumerate_chatgpt_storage_regions(): void
    {
        $config = (string) file_get_contents(dirname(__DIR__, 4) . '/mu-plugins/nhk-chatgpt-file-transport.php');
        self::assertStringNotContainsString("nhk_chatgpt_file_allowed_hosts", $config);
        self::assertStringNotContainsString('blob.core.windows.net', $config);
        self::assertStringNotContainsString('oaiusercontent.com', $config);
    }
}
