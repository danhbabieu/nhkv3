<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;

/** Admission for exact Source/Knowledge/Evidence children of a Video Capture. */
final class CaptureDependencyStagingAdmission
{
    private string $lastReason = 'NOT_EVALUATED';

    public function reason(): string
    {
        return $this->lastReason;
    }

    public function __invoke(bool $admitted, array $scope, CaptureRecord $capture, array $input, array $assets): bool
    {
        if ($admitted) { $this->lastReason = 'ALREADY_ADMITTED'; return true; }
        $this->lastReason = 'DEPENDENCY_SCOPE_INVALID';
        $contextIntent = is_array($capture->context['content_intent'] ?? null) ? $capture->context['content_intent'] : [];
        $planningInput = is_array($capture->context['planning_input'] ?? null) ? $capture->context['planning_input'] : [];
        $intent = strtoupper(trim((string) ($contextIntent['intent'] ?? $planningInput['intent'] ?? $input['intent'] ?? '')));
        if (!in_array($intent, ['VIDEO', 'KNOWLEDGE_DELTA'], true)) { $this->lastReason = 'CAPTURE_CONTENT_INTENT_NOT_VIDEO'; return false; }
        if (($scope['approved'] ?? false) !== true
            || ($scope['environment'] ?? '') !== 'staging'
            || ($scope['semantic_write_policy'] ?? '') !== 'PROJECT_BUILD'
            || ($scope['entrypoint'] ?? '') !== 'nhk.capture.ingest'
            || ($scope['capture_id'] ?? '') !== $capture->captureId
            || ($scope['capture_fingerprint'] ?? '') !== $capture->requestFingerprint
            || (int) ($scope['capture_revision'] ?? 0) !== $capture->revision
            || !in_array((string) ($scope['operation_family'] ?? ''), ['source_evidence_reconciliation', 'knowledge_delta'], true)
            || !in_array((string) ($scope['entity_type'] ?? ''), ['source', 'knowledge', 'evidence'], true)
            || !in_array((string) ($scope['operation'] ?? ''), ['ingest', 'create', 'update'], true)) { $this->lastReason = 'DEPENDENCY_OPERATION_NOT_ALLOWED'; return false; }
        if (preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['plan_fingerprint'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['proposal_command_fingerprint'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['payload_fingerprint'] ?? '')) !== 1) { $this->lastReason = 'DEPENDENCY_FINGERPRINT_INVALID'; return false; }
        $operation = (string) ($scope['operation'] ?? '');
        if ($operation === 'update' ? (int) ($scope['expected_revision'] ?? 0) < 1 : (int) ($scope['expected_revision'] ?? 0) !== 0) { $this->lastReason = $operation === 'update' ? 'UPDATE_REVISION_REQUIRED' : 'CREATE_REVISION_NOT_ZERO'; return false; }
        $this->lastReason = 'ADMITTED';
        return true;
    }
}
