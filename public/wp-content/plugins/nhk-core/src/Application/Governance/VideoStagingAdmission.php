<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Generic bounded admission for a server-issued Capture-owned Video plan. */
final class VideoStagingAdmission
{
    public function __construct(private ?VideoRepository $videos = null) {}

    /** @param array<string,mixed> $scope @param array<string,mixed> $input @param list<array<string,mixed>> $assets */
    public function __invoke(bool $admitted, array $scope, CaptureRecord $capture, array $input, array $assets): bool
    {
        if ($admitted) return true;
        if (($scope['approved'] ?? false) !== true
            || ($scope['environment'] ?? '') !== 'staging'
            || ($scope['semantic_write_policy'] ?? 'PROJECT_BUILD') !== 'PROJECT_BUILD'
            || ($scope['operation_family'] ?? '') !== 'governed_video_plan'
            || ($scope['entity_type'] ?? '') !== 'video'
            || !in_array((string) ($scope['operation'] ?? ''), ['ingest', 'update'], true)
            || ($scope['writer'] ?? '') !== 'canonical_governed'
            || (($scope['entrypoint'] ?? $scope['canonical_entrypoint'] ?? '') !== 'nhk.capture.ingest')
            || ($scope['capture_id'] ?? '') !== $capture->captureId
            || ($scope['capture_fingerprint'] ?? '') !== $capture->requestFingerprint
            || !CaptureVideoIntent::matches($capture, $input)
            || !preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['plan_fingerprint'] ?? ''))
            || !preg_match('/^[a-f0-9]{64}$/i', (string) ($scope['proposal_command_fingerprint'] ?? ''))) return false;

        // Scope issuance supplies the exact final plan as an admission asset.
        // Fall back to the persisted Capture asset only for legacy callers
        // that do not yet provide a final command packet.
        $candidateAssets = $assets !== [] ? $assets : $capture->assets;
        $videoAssets = array_values(array_filter($candidateAssets, static fn (mixed $asset): bool => is_array($asset) && ($asset['kind'] ?? '') === 'video'));
        if (count($videoAssets) !== 1) return false;
        $video = is_array($videoAssets[0]['video_proposal'] ?? null) ? $videoAssets[0]['video_proposal'] : [];
        $payload = is_array($video['payload'] ?? null) ? $video['payload'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $subject = is_array($metadata['subject_resolution_packet'] ?? null) ? $metadata['subject_resolution_packet'] : [];
        if (!UuidCodec::isValid((string) ($subject['id'] ?? '')) || trim((string) ($subject['type'] ?? '')) === '' || in_array(strtoupper((string) ($subject['status'] ?? '')), ['AMBIGUOUS', 'CONFLICT', 'MISSING'], true)) return false;

        $source = is_array($metadata['source'] ?? null) ? $metadata['source'] : (is_array($metadata['source_snapshot'] ?? null) ? $metadata['source_snapshot'] : []);
        foreach (['platform', 'external_video_id', 'canonical_source_url'] as $field) {
            if (isset($scope[$field]) && (string) ($scope[$field] ?? '') !== (string) ($source[$field] ?? '')) return false;
        }
        $scopeSubject = is_array($scope['subject'] ?? null) ? $scope['subject'] : [];
        if (($scope['subject_id'] ?? '') !== '' && (string) $scope['subject_id'] !== (string) $subject['id']) return false;
        if (($scopeSubject['uuid'] ?? '') !== '' && (string) $scopeSubject['uuid'] !== (string) $subject['id']) return false;
        if (($scopeSubject['type'] ?? '') !== '' && (string) $scopeSubject['type'] !== (string) $subject['type']) return false;

        $operation = (string) ($scope['operation'] ?? '');
        $targetUuid = (string) ($scope['target_uuid'] ?? $scope['proposed_uuid'] ?? '');
        if (!UuidCodec::isValid($targetUuid) || (string) ($payload['canonical_id'] ?? $video['target_uuid'] ?? $video['subject_id'] ?? '') !== $targetUuid) return false;
        if ($operation === 'update') {
            if ((int) ($scope['expected_revision'] ?? 0) < 1 || (isset($payload['expected_revision']) && (int) $payload['expected_revision'] !== (int) $scope['expected_revision']) || ($this->videos !== null && (($canonical = $this->videos->findByCanonicalId($targetUuid)) === null || $canonical->revision !== (int) $scope['expected_revision']))) return false;
            return true;
        }
        if (($scope['create_semantics'] ?? '') !== 'ingest' || (int) ($scope['expected_revision'] ?? 0) !== 0 || $this->videos === null) return false;
        return $this->videos->findByExternalReference((string) ($scope['platform'] ?? ''), (string) ($scope['external_video_id'] ?? '')) === null;
    }
}
