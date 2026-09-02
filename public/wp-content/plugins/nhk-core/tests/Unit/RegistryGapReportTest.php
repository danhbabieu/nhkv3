<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\RegistryGapReport;
use NHK\Core\Domain\Graph\PredicateRegistry;
use PHPUnit\Framework\TestCase;

final class RegistryGapReportTest extends TestCase
{
    public function test_report_distinguishes_registered_predicates_from_registry_gaps(): void
    {
        $report = (new RegistryGapReport(new PredicateRegistry()))->read();

        self::assertSame('REGISTERED', $report['model_of']['classification']);
        self::assertSame('REGISTERED', $report['uses_movement']['classification']);
    }
}
