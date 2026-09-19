<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\Governance\CommandCanonicalizer;
use PHPUnit\Framework\TestCase;

final class CommandCanonicalizerParityTest extends TestCase
{
    public function testAssociativeOrderingIsIgnoredButSemanticListOrderingIsPreserved(): void
    {
        self::assertSame(
            CommandCanonicalizer::canonicalize(['b' => ['y' => 2, 'x' => 1], 'a' => null]),
            CommandCanonicalizer::canonicalize(['a' => null, 'b' => ['x' => 1, 'y' => 2]])
        );
        self::assertNotSame(
            CommandCanonicalizer::canonicalize(['items' => ['A', 'B']]),
            CommandCanonicalizer::canonicalize(['items' => ['B', 'A']])
        );
    }

    public function testAbsentEmptyAndTypedValuesRemainDistinct(): void
    {
        self::assertNotSame(CommandCanonicalizer::canonicalize([]), CommandCanonicalizer::canonicalize(['value' => null]));
        self::assertNotSame(CommandCanonicalizer::canonicalize(['value' => '1']), CommandCanonicalizer::canonicalize(['value' => 1]));
        self::assertNotSame(CommandCanonicalizer::canonicalize(['value' => '']), CommandCanonicalizer::canonicalize(['value' => null]));
    }
}
