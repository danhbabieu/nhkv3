<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;

/** Admission for exact Source/Knowledge/Evidence children of a Video Capture. */
final class CaptureDependencyStagingAdmission
{
    public function __invoke(bool $admitted, array $scope, CaptureRecord $capture, array $input, array $assets): bool
    {
        if ($admitted) return true;
        if (($scope['approved'] ?? false) !== true
            || ($scope['environment'] ?? '') !== 'staging'
            || ($scope['semantic_write_policy'] ?? '') !== 'PROJECT_BUILD'
            || ($scope['entrypoint'] ?? '') !== 'nhk.capture.ingest'
            || ($scope['capture_id'] ?? '') !== $capture->captureId
            || ($scope['capture_fingerprint'] ?? '') !== $capture->requestFingerprint
            || strtoupper((string) ($capture->context['purpose'] ?? $input['intent'] ?? '')) !== 'VIDEO'
            || !in_array((string) ($scope['operation_family'] ?? ''), ['source_evidence_reconciliation', 'knowledge_delta'], true)
            || !in_array((string) ($scope['entity_type'] ?? ''), ['source', 'knowledge', 'evidence'], true)
            || (string) ($scope['operation'] ?? '') !== 'ingest') return false;
        return preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['plan_fingerprint'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['proposal_command_fingerprint'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['payload_fingerprint'] ?? '')) === 1;
    }
}
