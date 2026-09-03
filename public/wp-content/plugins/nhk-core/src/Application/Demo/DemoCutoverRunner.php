<?php
declare(strict_types=1);

namespace NHK\Core\Application\Demo;

use NHK\Core\Contracts\Demo\CutoverPorts;

final class DemoCutoverRunner
{
    private ?CutoverRunResult $prepared = null;

    public function __construct(private readonly CutoverPorts $ports) {}

    public function prepare(DemoCutoverContext $context): CutoverRunResult
    {
        $stages = ['safety', 'deploy', 'verify', 'preflight', 'graph', 'editorial', 'inventory'];
        foreach ($stages as $stage) {
            $result = ($this->ports->{$stage})($context);
            if (!$result instanceof StageResult || !$result->isPass()) {
                return $this->stop($result instanceof StageResult ? $result : StageResult::failed('INVALID_STAGE_RESULT'));
            }
        }

        $plan = ($this->ports->plan)($context);
        if (!$plan instanceof StageResult || !$plan->isPass() || $plan->fingerprint === null) {
            return $this->stop($plan instanceof StageResult ? $plan : StageResult::failed('INVALID_PLAN_RESULT'));
        }
        $submitted = ($this->ports->submit)($context, $plan->fingerprint);
        if (!$submitted instanceof StageResult || !$submitted->isPass() || $submitted->identifier === null) {
            return $this->stop($submitted instanceof StageResult ? $submitted : StageResult::failed('INVALID_SUBMIT_RESULT'));
        }
        $approval = ($this->ports->approval)($context, $submitted->identifier, $plan->fingerprint);
        if (!$approval instanceof StageResult || !$approval->isPass()) {
            return $this->stop($approval instanceof StageResult ? $approval : StageResult::failed('INVALID_APPROVAL_RESULT'));
        }

        $this->prepared = new CutoverRunResult('awaiting_approval', null, $submitted->identifier, $plan->fingerprint);
        return $this->prepared;
    }

    public function apply(string $approvalFingerprint): CutoverRunResult
    {
        if ($this->prepared === null || $this->prepared->proposalFingerprint === null) {
            return new CutoverRunResult('blocked', 'PREPARE_REQUIRED');
        }
        if (!hash_equals($this->prepared->proposalFingerprint, $approvalFingerprint)) {
            return new CutoverRunResult('blocked', 'APPROVAL_FINGERPRINT_MISMATCH', $this->prepared->proposalId, $this->prepared->proposalFingerprint);
        }

        foreach (['eligibility', 'apply', 'readback', 'evidence'] as $stage) {
            $result = ($this->ports->{$stage})($this->prepared->proposalId, $this->prepared->proposalFingerprint);
            if (!$result instanceof StageResult || !$result->isPass()) {
                return new CutoverRunResult($result instanceof StageResult ? $result->status : 'failed', $result instanceof StageResult ? $result->reasonCode : 'INVALID_STAGE_RESULT', $this->prepared->proposalId, $this->prepared->proposalFingerprint);
            }
        }

        return new CutoverRunResult('applied', null, $this->prepared->proposalId, $this->prepared->proposalFingerprint);
    }

    private function stop(StageResult $result): CutoverRunResult
    {
        return new CutoverRunResult($result->status, $result->reasonCode, $result->identifier, $result->fingerprint);
    }
}
