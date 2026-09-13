<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OperationalRuntimeAcceptanceScriptTest extends TestCase
{
    public function test_acceptance_script_is_read_only_and_fail_closed(): void
    {
        $path = dirname(__DIR__, 6) . '/tools/operational-runtime-acceptance.php';
        self::assertFileExists($path);
        $contents = (string) file_get_contents($path);

        foreach ([
            'OPERATIONAL_TARGET_FORBIDDEN',
            'DATABASE_BINDING_UNAVAILABLE',
            'MIGRATION_REQUIRED',
            'CANONICAL_DOCUMENTATION_UNAVAILABLE',
            'READ_SURFACE_UNAVAILABLE',
            'CONNECTOR_ID_REQUIRED',
            'WRITE_POLICY_UNVERIFIED',
            'database_binding_id',
            'nhk.capture.ingest',
            'nhk.proposal.review',
            'nhk.proposal.eligibility',
            'nhk.proposal.apply',
            'demo.1945.vn',
            'recovery',
        ] as $required) {
            self::assertStringContainsString($required, $contents, $required);
        }

        foreach ([
            'RemoteDeploymentAdapter',
            'RemoteRuntimeAdapter',
            'nhk.capture.ingest(',
            'update_option',
            'INSERT INTO',
            'UPDATE ',
            'DELETE FROM',
            'TRUNCATE',
            'DROP TABLE',
            'ControlledApply',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $contents, $forbidden);
        }
    }
}
