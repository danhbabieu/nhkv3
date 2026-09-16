<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ChatGptFileAllowlistConfigTest extends TestCase
{
    public function test_staging_does_not_enumerate_chatgpt_storage_regions(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 4) . '/mu-plugins/nhk-chatgpt-file-transport.php');
    }
}
