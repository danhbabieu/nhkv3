<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Demo\DemoCutoverContext;
use NHK\Core\Application\Demo\DemoCutoverRunner;
use NHK\Core\Application\Demo\StageResult;
use NHK\Core\Contracts\Demo\CutoverPorts;
use PHPUnit\Framework\TestCase;

final class DemoCutoverRunnerTest extends TestCase
{
    public function test_prepare_stops_at_human_approval_and_never_applies(): void
    {
        $calls = [];
        $ports = $this->ports($calls);
        $runner = new DemoCutoverRunner($ports);

        $result = $runner->prepare(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-1'));

        self::assertSame('awaiting_approval', $result->status);
        self::assertSame('proposal-1', $result->proposalId);
        self::assertNotContains('apply', $calls);
        self::assertSame(['safety', 'deploy', 'verify', 'preflight', 'graph', 'editorial', 'inventory', 'plan', 'submit', 'approval'], $calls);
    }

    public function test_blocked_runtime_stops_before_reads_or_proposals(): void
    {
        $calls = [];
        $ports = $this->ports($calls, StageResult::blocked('AUTHENTICATED_RUNTIME_REQUIRED'));

        $result = (new DemoCutoverRunner($ports))->prepare(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-2'));

        self::assertSame('blocked', $result->status);
        self::assertSame('AUTHENTICATED_RUNTIME_REQUIRED', $result->reasonCode);
        self::assertSame(['safety', 'deploy', 'verify', 'preflight'], $calls);
    }

    public function test_apply_requires_matching_approval_and_readback(): void
    {
        $calls = [];
        $runner = new DemoCutoverRunner($this->ports($calls));
        $prepared = $runner->prepare(new DemoCutoverContext('demo.1945.vn', 'odo', 'abc123', 'run-3'));

        $result = $runner->apply('wrong-fingerprint');
        self::assertSame('blocked', $result->status);
        self::assertSame('APPROVAL_FINGERPRINT_MISMATCH', $result->reasonCode);
        self::assertSame($prepared->proposalFingerprint, $runner->apply($prepared->proposalFingerprint)->proposalFingerprint);
        self::assertContains('apply', $calls);
        self::assertContains('readback', $calls);
    }

    /** @param list<string> $calls */
    private function ports(array &$calls, ?StageResult $runtime = null): CutoverPorts
    {
        return new CutoverPorts(
            function () use (&$calls): StageResult { $calls[] = 'safety'; return StageResult::pass(); },
            function () use (&$calls): StageResult { $calls[] = 'deploy'; return StageResult::pass(); },
            function () use (&$calls): StageResult { $calls[] = 'verify'; return StageResult::pass(); },
            function () use (&$calls, $runtime): StageResult { $calls[] = 'preflight'; return $runtime ?? StageResult::pass(); },
            function () use (&$calls): StageResult { $calls[] = 'graph'; return StageResult::pass(); },
            function () use (&$calls): StageResult { $calls[] = 'editorial'; return StageResult::pass(); },
            function () use (&$calls): StageResult { $calls[] = 'inventory'; return StageResult::pass(); },
            function () use (&$calls): StageResult { $calls[] = 'plan'; return StageResult::pass('plan-1', 'fingerprint-1'); },
            function () use (&$calls): StageResult { $calls[] = 'submit'; return StageResult::pass('proposal-1', 'fingerprint-1'); },
            function () use (&$calls): StageResult { $calls[] = 'approval'; return StageResult::pass(); },
            function () use (&$calls): StageResult { $calls[] = 'eligibility'; return StageResult::pass(); },
            function () use (&$calls): StageResult { $calls[] = 'apply'; return StageResult::pass(); },
            function () use (&$calls): StageResult { $calls[] = 'readback'; return StageResult::pass(); },
            function (): StageResult { return StageResult::pass(); },
        );
    }
}
