<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Admission for exact existing-Media metadata repairs from Capture. */
final class MediaMetadataStagingAdmission
{
    public function __construct(private MediaRepository $media) {}

    public function __invoke(bool $admitted, array $scope, CaptureRecord $capture, array $input, array $assets): bool
    {
        if ($admitted) return true;
        if (($scope['approved'] ?? false) !== true
            || ($scope['environment'] ?? '') !== 'staging'
            || ($scope['operation_family'] ?? '') !== 'media_metadata_reconciliation'
            || ($scope['entity_type'] ?? '') !== 'media'
            || ($scope['operation'] ?? '') !== 'update'
            || ($scope['writer'] ?? '') !== 'canonical_governed'
            || ($scope['entrypoint'] ?? '') !== 'nhk.capture.ingest'
            || ($scope['capture_id'] ?? '') !== $capture->captureId
            || ($scope['capture_fingerprint'] ?? '') !== $capture->requestFingerprint) return false;
        $mediaId = trim((string) ($scope['target_uuid'] ?? ''));
        $revision = (int) ($scope['expected_revision'] ?? 0);
        $media = UuidCodec::isValid($mediaId) ? $this->media->findByCanonicalId($mediaId) : null;
        if ($media === null || !$media->active || $media->revision !== $revision) return false;
        $operations = array_values(array_filter((array) ($input['media_operations'] ?? []), 'is_array'));
        if (count($operations) !== 1) return false;
        $operation = $operations[0];
        return strtolower(trim((string) ($operation['operation'] ?? ''))) === 'update'
            && (string) (($operation['media']['id'] ?? $operation['media_ref']['id'] ?? '')) === $mediaId
            && (int) ($operation['expected_revision'] ?? $revision) === $revision
            && preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['payload_fingerprint'] ?? '')) === 1;
    }
}
