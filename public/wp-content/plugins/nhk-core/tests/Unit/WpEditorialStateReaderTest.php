<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Article\WpEditorialStateReader;
use PHPUnit\Framework\TestCase;

final class WpEditorialStateReaderTest extends TestCase
{
    public function test_reader_fails_closed_when_wordpress_post_runtime_is_unavailable(): void
    {
        self::assertNull((new WpEditorialStateReader())->read(55));
    }
}
