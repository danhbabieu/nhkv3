<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Capture\{CaptureRecord, CapturePurpose, CaptureStage};
use NHK\Core\Domain\Governance\CommandCanonicalizer;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Capture-owned Authority operator state. It delegates planning and editorial
 * work to injected owners and never writes Authority, Graph or Proposal data.
 */
final class AuthorityCaptureService
{
    /** @param callable(array<string,mixed>,CaptureRecord):array<string,mixed> $planner @param (callable(array<string,mixed>,CaptureRecord):array<string,mixed>)|null $mixedEditorial @param (callable(CaptureRecord,array<string,mixed>,array<string>):array<string,mixed>)|null $applyPlan @param (callable(CaptureRecord,array<string,mixed>):array<string,mixed>)|null $mixedContinuation */
    public function __construct(private CaptureRepository $captures, private $planner, private $mixedEditorial = null, private $applyPlan = null, private $mixedContinuation = null) {}

    /** @param array<string,mixed> $input */
    public function execute(array $input): CaptureRecord
    {
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if ($key === '') throw new \InvalidArgumentException('Capture idempotency key is required.');
        $purpose = CapturePurposePolicy::resolve($input);
        if ($purpose === CapturePurpose::EDITORIAL) throw new \InvalidArgumentException('AUTHORITY_PURPOSE_CONFLICT');
        $intent = is_array($input['authority_intent'] ?? null) ? $input['authority_intent'] : [];
        if (($intent['mode'] ?? '') !== 'PLAN') throw new \InvalidArgumentException('AUTHORITY_CONTINUATION_REQUIRED');
        $fingerprint = $this->fingerprint($input);
        $existing = $this->captures->findByIdempotencyKey($key);
        if ($existing !== null) {
            if (!hash_equals($existing->requestFingerprint, $fingerprint)) return $this->conflict($existing, $fingerprint);
            return $existing;
        }

        $record = $this->captures->create(new CaptureRecord(
            UuidCodec::newV7(), $key, $fingerprint,
            CaptureStage::RECEIVED->value, 'RECEIVED', null, null, [], [
                'purpose' => $purpose->value,
                'raw_input' => trim((string) ($input['text'] ?? $input['content'] ?? '')),
                'subject_hints' => is_array($input['subject_hints'] ?? null) ? array_values($input['subject_hints']) : [],
                'observations' => is_array($input['observations'] ?? null) ? $input['observations'] : [],
                'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
                'authority_intent' => is_array($input['authority_intent'] ?? null) ? $input['authority_intent'] : [],
                'documentation_checkpoint' => is_array($input['documentation_checkpoint'] ?? null) ? $input['documentation_checkpoint'] : [],
                'planning_input' => $input,
            ], [], []
        ));

        $plan = ($this->planner)($input, $record);
        $editorial = [];
        if ($purpose === CapturePurpose::MIXED) {
            if (!is_callable($this->mixedEditorial)) throw new \RuntimeException('MIXED_EDITORIAL_OWNER_UNAVAILABLE');
            $editorial = ($this->mixedEditorial)($input, $record);
        }
        $context = $record->context;
        $context['authority_plan'] = $plan;
        $context['plan_fingerprint'] = (string) ($plan['plan_fingerprint'] ?? '');
        $context['planning_revision'] = $record->revision;
        $articleId = $editorial === [] ? null : (int) ($editorial['post_id'] ?? 0);
        if ($editorial !== [] && $articleId < 1) throw new \RuntimeException('ARTICLE_DRAFT_READBACK_UNAVAILABLE');
        if ($editorial !== []) $context['mixed_editorial'] = ['status' => 'PENDING_AUTHORITY', 'post_id' => $articleId];
        return $this->captures->save(new CaptureRecord(
            $record->captureId, $record->idempotencyKey, $record->requestFingerprint,
            CaptureStage::AUTHORITY_PLANNED->value, 'PLANNED', $articleId,
            $editorial === [] ? null : (string) ($editorial['state_token'] ?? ''),
            $record->assets, $context, ['authority_plan' => $plan], $record->phaseReceipts,
            $record->revision + 1, $record->createdAt, gmdate('Y-m-d H:i:s.u')
        ));
    }

    /** Continue the persisted Capture with an exact, structured approval packet. */
    public function continueWithApproval(string $captureId, array $input): CaptureRecord
    {
        $record = $this->captures->findById($captureId);
        if (!$record instanceof CaptureRecord) throw new \InvalidArgumentException('CAPTURE_NOT_FOUND');
        $purpose = CapturePurpose::tryFrom((string) ($record->context['purpose'] ?? ''));
        if (!in_array($purpose, [CapturePurpose::AUTHORITY, CapturePurpose::MIXED], true)) throw new \InvalidArgumentException('CAPTURE_PURPOSE_NOT_AUTHORITY');
        $intent = is_array($input['authority_intent'] ?? null) ? $input['authority_intent'] : [];
        if (($intent['mode'] ?? '') !== 'APPLY_APPROVED_PLAN') throw new \InvalidArgumentException('AUTHORITY_APPROVAL_PACKET_REQUIRED');
        $approvedFingerprint = trim((string) ($intent['approved_plan_fingerprint'] ?? ''));
        $approvedIds = array_values(array_unique(array_filter(array_map('strval', (array) ($intent['approved_candidate_ids'] ?? [])))));
        if (!preg_match('/^[a-f0-9]{64}$/i', $approvedFingerprint) || $approvedIds === []) throw new \InvalidArgumentException('AUTHORITY_APPROVAL_PACKET_INVALID');
        $previous = is_array($record->context['authority_result'] ?? null) ? $record->context['authority_result'] : [];
        // A completed apply is replay-safe. Pending/review results must be
        // replanned so policy, registry and documentation changes can force
        // PLAN_REAPPROVAL_REQUIRED before any proposal write.
        if (($previous['approved_plan_fingerprint'] ?? '') === $approvedFingerprint
            && $this->sameIds((array) ($previous['approved_candidate_ids'] ?? []), $approvedIds)
            && (($previous['result']['status'] ?? '') === 'APPLIED' || $record->stage === CaptureStage::AUTHORITY_APPLIED->value)) return $record;
        $planningInput = is_array($record->context['planning_input'] ?? null) ? $record->context['planning_input'] : ['text' => (string) ($record->context['raw_input'] ?? ''), 'purpose' => $purpose->value];
        $planningInput['purpose'] = $purpose->value;
        $planningInput['authority_intent'] = ['mode' => 'PLAN'];
        $plan = ($this->planner)($planningInput, $record);
        $currentFingerprint = (string) ($plan['plan_fingerprint'] ?? '');
        if ($currentFingerprint === '' || !hash_equals($approvedFingerprint, $currentFingerprint)) throw new \InvalidArgumentException('PLAN_REAPPROVAL_REQUIRED');
        if (!is_callable($this->applyPlan)) throw new \RuntimeException('AUTHORITY_PLAN_EXECUTOR_UNAVAILABLE');
        $result = ($this->applyPlan)($record, $plan, $approvedIds);
        $context = $record->context;
        $saveBase = $record;
        $context['authority_result'] = ['approved_plan_fingerprint' => $approvedFingerprint, 'approved_candidate_ids' => $approvedIds, 'result' => $result];
        $status = strtoupper((string) ($result['status'] ?? '')) === 'APPLIED' ? 'APPLIED' : 'APPROVAL_PENDING';
        if ($purpose === CapturePurpose::MIXED && $status === 'APPLIED' && is_callable($this->mixedContinuation)) {
            $reconciliation = ($this->mixedContinuation)($record, $result);
            // The editorial coordinator may advance the same Capture while
            // reconciling the existing Post. Carry that canonical record
            // forward so this Authority result cannot overwrite its newer
            // revision/context.
            if (($reconciliation['capture_record'] ?? null) instanceof CaptureRecord) {
                $saveBase = $reconciliation['capture_record'];
                unset($reconciliation['capture_record']);
                $context = $saveBase->context;
                $context['authority_result'] = ['approved_plan_fingerprint' => $approvedFingerprint, 'approved_candidate_ids' => $approvedIds, 'result' => $result];
            }
            $context['mixed_editorial_reconciliation'] = $reconciliation;
        }
        return $this->captures->save(new CaptureRecord(
            $saveBase->captureId, $saveBase->idempotencyKey, $saveBase->requestFingerprint,
            $status === 'APPLIED' ? CaptureStage::AUTHORITY_APPLIED->value : CaptureStage::AUTHORITY_PLANNED->value,
            $status, $saveBase->articleId, $saveBase->articleStateToken, $saveBase->assets, $context,
            $saveBase->diagnostics + ['authority_apply' => $result], $saveBase->phaseReceipts,
            $saveBase->revision + 1, $saveBase->createdAt, gmdate('Y-m-d H:i:s.u')
        ));
    }

    private function sameIds(array $left, array $right): bool
    {
        $left = array_values(array_unique(array_map('strval', $left))); $right = array_values(array_unique(array_map('strval', $right)));
        sort($left, SORT_STRING); sort($right, SORT_STRING); return $left === $right;
    }

    /** @param array<string,mixed> $input */
    private function fingerprint(array $input): string
    {
        return hash('sha256', CommandCanonicalizer::canonicalize([
            'idempotency_key' => (string) ($input['idempotency_key'] ?? ''),
            'purpose' => strtoupper(trim((string) ($input['purpose'] ?? ''))),
            'text' => trim((string) ($input['text'] ?? $input['content'] ?? '')),
            'subject_hints' => is_array($input['subject_hints'] ?? null) ? array_values($input['subject_hints']) : [],
            'observations' => is_array($input['observations'] ?? null) ? $input['observations'] : [],
            'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
            'authority_intent' => is_array($input['authority_intent'] ?? null) ? $input['authority_intent'] : [],
            'documentation_checkpoint' => is_array($input['documentation_checkpoint'] ?? null) ? $input['documentation_checkpoint'] : [],
        ]));
    }

    private function conflict(CaptureRecord $record, string $fingerprint): CaptureRecord
    {
        return new CaptureRecord($record->captureId, $record->idempotencyKey, $record->requestFingerprint, $record->stage, 'IDEMPOTENCY_CONFLICT', $record->articleId, $record->articleStateToken, $record->assets, $record->context, $record->diagnostics + ['failure' => ['code' => 'CAPTURE_IDEMPOTENCY_KEY_REUSED', 'request_fingerprint' => $fingerprint]], $record->phaseReceipts, $record->revision, $record->createdAt, $record->updatedAt);
    }
}
