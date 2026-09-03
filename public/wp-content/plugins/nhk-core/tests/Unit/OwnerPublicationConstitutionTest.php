<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OwnerPublicationConstitutionTest extends TestCase
{
    public function test_owner_publication_law_is_in_the_sole_constitution(): void
    {
        $constitution = file_get_contents(dirname(__DIR__, 6) . '/docs/constitution/NHK_V3_CONSTITUTION.md');
        self::assertIsString($constitution);
        foreach (['Owner Publication Override Law', '`PASS`', '`OWNER_REVIEW_REQUIRED`', '`SYSTEM_BLOCKED`', '30 minutes', '65.', '74.'] as $required) self::assertStringContainsString($required, $constitution);
    }
}
