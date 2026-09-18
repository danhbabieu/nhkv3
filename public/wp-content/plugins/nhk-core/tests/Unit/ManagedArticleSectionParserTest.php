<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Semantic\ManagedArticleSectionParser;
use PHPUnit\Framework\TestCase;

final class ManagedArticleSectionParserTest extends TestCase
{
    public function test_stale_nhk_sections_are_removed_but_manual_prose_is_preserved(): void
    {
        $parser = new ManagedArticleSectionParser();
        $stale = $parser->wrap('stale', hash('sha256', 'stale'), 'dep', 'Westminster Quarters');
        $current = "USER_AUTHORED\n\n{$stale}\n\nMORE_MANUAL";

        $result = $parser->reconcileStale($current, []);

        self::assertSame("USER_AUTHORED\n\nMORE_MANUAL", $result);
        self::assertStringNotContainsString('Westminster Quarters', $result);
        self::assertStringContainsString('USER_AUTHORED', $result);
        self::assertStringContainsString('MORE_MANUAL', $result);
    }
}
