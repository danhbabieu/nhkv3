<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Demo\CutoverEvidence;
use PHPUnit\Framework\TestCase;

final class DemoCutoverTypesTest extends TestCase
{
    public function test_evidence_redacts_secret_values_and_headers(): void
    {
        $safe = CutoverEvidence::redact(['token' => 'secret', 'headers' => ['Authorization' => 'Bearer abc'], 'count' => 2]);

        self::assertSame('[REDACTED]', $safe['token']);
        self::assertSame('[REDACTED]', $safe['headers']);
        self::assertSame(2, $safe['count']);
    }
}
