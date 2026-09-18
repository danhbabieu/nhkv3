<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;

/** Bounded admission for the explicitly approved W64 Video correction. */
final class VideoW64StagingAdmission
{
    private const CAPTURE_ID = '01a0b230-1f3a-7b9f-b569-fb67834ab579';
    private const REQUEST_FINGERPRINT = 'f66ae6b07e139655ef1b50fe0c876297c4c1873c151d6511ea84695c6916ec02';
    private const VIDEO_UUID = '01a0aaf8-2a84-7287-bbd8-70af4d5485e4';
    private const VARIANT_UUID = '24eaeba5-b5f9-420f-a2fe-50b1f2a6130f';

    /** @param array<string,mixed> $scope @param array<string,mixed> $input @param list<array<string,mixed>> $assets */
    public function __invoke(bool $admitted, array $scope, CaptureRecord $capture, array $input, array $assets): bool
    {
        if ($admitted) return true;
        if (($scope['approved'] ?? false) !== true
            || ($scope['environment'] ?? '') !== 'staging'
            || ($scope['operation_family'] ?? '') !== 'governed_video_plan'
            || ($scope['entity_type'] ?? '') !== 'video'
            || ($scope['operation'] ?? '') !== 'update'
            || ($scope['writer'] ?? '') !== 'canonical_governed'
            || ($scope['entrypoint'] ?? '') !== 'nhk.capture.ingest'
            || ($scope['capture_id'] ?? '') !== self::CAPTURE_ID
            || ($scope['capture_fingerprint'] ?? '') !== self::REQUEST_FINGERPRINT
            || ($scope['target_uuid'] ?? '') !== self::VIDEO_UUID
            || (int) ($scope['expected_revision'] ?? 0) !== 5
            || $capture->captureId !== self::CAPTURE_ID
            || $capture->requestFingerprint !== self::REQUEST_FINGERPRINT) return false;

        $videoAssets = array_values(array_filter($capture->assets, static fn (mixed $asset): bool => is_array($asset) && ($asset['kind'] ?? '') === 'video'));
        if (count($videoAssets) !== 1) return false;
        $video = is_array($videoAssets[0]['video_proposal'] ?? null) ? $videoAssets[0]['video_proposal'] : [];
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $subject = is_array($metadata['subject_resolution_packet'] ?? null) ? $metadata['subject_resolution_packet'] : [];

        return ($payload['canonical_id'] ?? $video['target_uuid'] ?? $video['subject_id'] ?? '') === self::VIDEO_UUID
            && ($subject['id'] ?? '') === self::VARIANT_UUID
            && ($subject['type'] ?? '') === 'variant';
    }
}
