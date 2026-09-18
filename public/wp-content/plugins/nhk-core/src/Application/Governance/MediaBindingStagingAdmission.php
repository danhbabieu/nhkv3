<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Media\RepresentativeEligibilityRegistry;
use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Canonical provider for the staging acceptance admission hook.
 *
 * Admission is derived from the immutable Capture request and live canonical
 * owners. It deliberately knows no staging IDs and never changes production
 * behavior; the verifier is the owner of environment, expiry and signature.
 */
final class MediaBindingStagingAdmission
{
    public function __construct(
        private MediaRepository $media,
        private AuthorityRepository $authority,
        private RepresentativeEligibilityRegistry $eligibility = new RepresentativeEligibilityRegistry(),
    ) {}

    /** @param array<string,mixed> $scope @param array<string,mixed> $input @param list<array<string,mixed>> $assets */
    public function __invoke(array $scope, CaptureRecord $capture, array $input, array $assets): bool
    {
        if (($scope['approved'] ?? false) !== true
            || ($scope['environment'] ?? '') !== 'staging'
            || ($scope['operation_family'] ?? '') !== 'media_usage_reconciliation'
            || ($scope['entity_type'] ?? '') !== 'media'
            || ($scope['operation'] ?? '') !== 'representative_bind'
            || ($scope['writer'] ?? '') !== 'canonical_media_binding'
            || ($scope['entrypoint'] ?? '') !== 'nhk.capture.ingest'
            || strtoupper(trim((string) ($scope['intent'] ?? ''))) !== 'MEDIA_ENRICHMENT'
            || (string) ($scope['capture_id'] ?? '') !== $capture->captureId
            || (string) ($scope['capture_fingerprint'] ?? '') !== $capture->requestFingerprint) return false;

        $requested = array_values(array_filter((array) ($input['media_bindings'] ?? []), 'is_array'));
        $admitted = array_values(array_filter((array) ($scope['bindings'] ?? []), 'is_array'));
        if ($requested === [] || count($requested) !== count($admitted)) return false;

        foreach ($requested as $index => $binding) {
            $entry = $admitted[$index] ?? null;
            if (!is_array($entry) || !$this->bindingMatches($binding, $entry, $assets)) return false;
            $target = (array) ($entry['target'] ?? []);
            $type = strtolower(trim((string) ($target['type'] ?? '')));
            $id = trim((string) ($target['id'] ?? ''));
            $mediaId = trim((string) ($entry['media_id'] ?? ''));
            if ($type === '' || !UuidCodec::isValid($id) || !UuidCodec::isValid($mediaId)
                || ($entry['role'] ?? '') !== 'representative'
                || ($entry['selection_source'] ?? '') !== 'USER_EXPLICIT'
                || ($entry['selection_policy'] ?? '') !== 'PINNED') return false;

            $media = $this->media->findByCanonicalId($mediaId);
            $targetEntity = $this->authority->findByCanonicalId($id);
            if ($media === null || !$media->active || $media->isSystemPlaceholder()
                || $targetEntity === null || !$targetEntity->active()
                || $targetEntity->entityType !== $type
                || !$this->eligibility->isEligible($type, [
                    'scope' => 'representative',
                    'scope_justified' => true,
                    'representative_relevance' => true,
                ])) return false;
        }
        return true;
    }

    /** @param array<string,mixed> $binding @param array<string,mixed> $entry @param list<array<string,mixed>> $assets */
    private function bindingMatches(array $binding, array $entry, array $assets): bool
    {
        $reference = is_array($binding['media_ref'] ?? null) ? $binding['media_ref'] : [];
        $mediaId = trim((string) ($reference['media_id'] ?? ''));
        if ($mediaId === '' && isset($reference['item_index'])) {
            $asset = $assets[(int) $reference['item_index']] ?? null;
            $mediaId = is_array($asset) ? trim((string) ($asset['media_id'] ?? '')) : '';
        }
        $target = is_array($binding['target'] ?? null) ? $binding['target'] : [];
        $entryTarget = is_array($entry['target'] ?? null) ? $entry['target'] : [];
        return $mediaId !== ''
            && $mediaId === (string) ($entry['media_id'] ?? '')
            && strtolower(trim((string) ($target['type'] ?? ''))) === (string) ($entryTarget['type'] ?? '')
            && trim((string) ($target['id'] ?? '')) === (string) ($entryTarget['id'] ?? '')
            && strtolower(trim((string) ($binding['role'] ?? 'representative'))) === (string) ($entry['role'] ?? '')
            && strtoupper(trim((string) ($binding['selection_source'] ?? 'USER_EXPLICIT'))) === (string) ($entry['selection_source'] ?? '')
            && strtoupper(trim((string) ($binding['selection_policy'] ?? 'PINNED'))) === (string) ($entry['selection_policy'] ?? '');
    }
}
