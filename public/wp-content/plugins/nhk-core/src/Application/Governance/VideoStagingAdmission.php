<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;

/** Generic bounded admission for a server-issued Capture-owned Video plan. */
final class VideoStagingAdmission
{
    /** @param array<string,mixed> $scope @param array<string,mixed> $input @param list<array<string,mixed>> $assets */
    public function __invoke(bool $admitted, array $scope, CaptureRecord $capture, array $input, array $assets): bool
    {
        if ($admitted) return true;
        if (($scope['approved'] ?? false) !== true
            || ($scope['environment'] ?? '') !== 'staging'
            || ($scope['operation_family'] ?? '') !== 'governed_video_plan'
            || ($scope['entity_type'] ?? '') !== 'video'
            || !in_array((string) ($scope['operation'] ?? ''), ['update', 'retire', 'reactivate'], true)
            || ($scope['writer'] ?? '') !== 'canonical_governed'
            || ($scope['entrypoint'] ?? '') !== 'nhk.capture.ingest'
            || ($scope['capture_id'] ?? '') !== $capture->captureId
            || ($scope['capture_fingerprint'] ?? '') !== $capture->requestFingerprint
            || !\NHK\Core\Shared\Uuid\UuidCodec::isValid((string) ($scope['target_uuid'] ?? ''))
            || (int) ($scope['expected_revision'] ?? 0) < 1) return false;

        $videoAssets = array_values(array_filter($capture->assets, static fn (mixed $asset): bool => is_array($asset) && ($asset['kind'] ?? '') === 'video'));
        if (count($videoAssets) !== 1) return false;
        $video = is_array($videoAssets[0]['video_proposal'] ?? null) ? $videoAssets[0]['video_proposal'] : [];
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $subject = is_array($metadata['subject_resolution_packet'] ?? null) ? $metadata['subject_resolution_packet'] : [];

        $targetUuid = (string) ($scope['target_uuid'] ?? '');
        return ($payload['canonical_id'] ?? $video['target_uuid'] ?? $video['subject_id'] ?? '') === $targetUuid
            && (!isset($payload['expected_revision']) || (int) $payload['expected_revision'] === (int) ($scope['expected_revision'] ?? 0))
            && \NHK\Core\Shared\Uuid\UuidCodec::isValid((string) ($subject['id'] ?? ''))
            && trim((string) ($subject['type'] ?? '')) !== ''
            && (!isset($scope['subject_id']) || (string) $scope['subject_id'] === (string) $subject['id']);
    }
}
