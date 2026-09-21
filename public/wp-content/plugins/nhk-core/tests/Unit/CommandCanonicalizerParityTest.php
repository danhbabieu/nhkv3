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

    public function testJsonNumericRoundTripCanonicalizesIntegralFloats_withoutCollapsingTypes(): void
    {
        self::assertSame(
            CommandCanonicalizer::canonicalize(['nested' => ['x' => 1.0, 'zero' => -0.0, 'items' => [2.0, 0.5]]]),
            CommandCanonicalizer::canonicalize(['nested' => ['x' => 1, 'zero' => 0, 'items' => [2, 0.5]]]),
        );
        self::assertNotSame(CommandCanonicalizer::canonicalize(['x' => 1]), CommandCanonicalizer::canonicalize(['x' => '1']));
        self::assertNotSame(CommandCanonicalizer::canonicalize(['x' => 1]), CommandCanonicalizer::canonicalize(['x' => true]));
        self::assertNotSame(CommandCanonicalizer::canonicalize(['x' => 0.95]), CommandCanonicalizer::canonicalize(['x' => 0.96]));
        self::assertNotSame(CommandCanonicalizer::canonicalize(['x' => 1.25]), CommandCanonicalizer::canonicalize(['x' => 1]));
    }

    public function testNonFiniteNumbers_fail_closed(): void
    {
        $this->expectException(\JsonException::class);
        CommandCanonicalizer::canonicalize(['x' => INF]);
    }
}
