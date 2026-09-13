<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OperationalRuntimeProvisioningDocumentationTest extends TestCase
{
    public function test_operational_runtime_runbook_contains_required_handoff_contract(): void
    {
        $path = dirname(__DIR__, 6) . '/docs/architecture/OPERATIONAL_RUNTIME_PROVISIONING_RUNBOOK.md';
        self::assertFileExists($path);
        $contents = (string) file_get_contents($path);

        foreach ([
            'PERSISTENT_CANONICAL_RUNTIME_READY',
            'CANONICAL_RUNTIME_NOT_PROVISIONED',
            'CANONICAL_RUNTIME_EXISTS_CONNECTOR_NOT_BOUND',
            'CANONICAL_RUNTIME_WRITE_POLICY_BLOCKED',
            'nhk_v3_test',
            'https://demo.1945.vn',
            'NHK_RUNTIME_MODE=recovery',
            'nhk.capture.ingest',
            'Proposal review',
            'Approval',
            'Eligibility',
            'Controlled Apply',
            'canonical read-back',
            'No semantic mutation',
        ] as $required) {
            self::assertStringContainsString($required, $contents, $required);
        }
    }
}
