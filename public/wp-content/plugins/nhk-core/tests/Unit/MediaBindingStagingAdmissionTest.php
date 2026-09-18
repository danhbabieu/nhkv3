<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\MediaBindingStagingAdmission;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Media\MediaRepository;
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Media\Media;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaBindingStagingAdmissionTest extends TestCase
{
    public function test_generic_existing_media_and_eligible_classification_are_admitted(): void
    {
        [$scope, $capture, $input] = $this->fixture();
        $media = $this->createMock(MediaRepository::class);
        $authority = $this->createMock(AuthorityRepository::class);
        $media->method('findByCanonicalId')->willReturn(new Media($scope['media_ids'][0], 'nhk:media:clock', 'Clock image', 'ready'));
        $authority->method('findByCanonicalId')->willReturn(new AuthorityEntity($scope['target']['id'], 'classification', 'nhk:classification:clock', 'Clock type', 1, []));

        self::assertTrue((new MediaBindingStagingAdmission($media, $authority))($scope, $capture, $input, []));
    }

    /** @dataProvider invalidAdmissionProvider */
    public function test_admission_fails_closed_for_tampering_or_unsupported_targets(string $case): void
    {
        [$scope, $capture, $input] = $this->fixture();
        $media = $this->createMock(MediaRepository::class);
        $authority = $this->createMock(AuthorityRepository::class);
        $media->method('findByCanonicalId')->willReturn(new Media($scope['media_ids'][0], 'nhk:media:clock', 'Clock image', 'ready'));
        $authority->method('findByCanonicalId')->willReturn(new AuthorityEntity($scope['target']['id'], 'classification', 'nhk:classification:clock', 'Clock type', 1, []));
        if ($case === 'target') $input['media_bindings'][0]['target']['id'] = UuidCodec::newV7();
        if ($case === 'fingerprint') $scope['capture_fingerprint'] = hash('sha256', 'altered');
        if ($case === 'operation') $scope['operation'] = 'media_ingest';
        if ($case === 'unsupported') {
            $scope['target']['type'] = 'unsupported';
            $scope['bindings'][0]['target']['type'] = 'unsupported';
            $input['media_bindings'][0]['target']['type'] = 'unsupported';
        }

        self::assertFalse((new MediaBindingStagingAdmission($media, $authority))($scope, $capture, $input, []));
    }

    public static function invalidAdmissionProvider(): array
    {
        return [['target'], ['fingerprint'], ['operation'], ['unsupported']];
    }

    /** @return array{0:array<string,mixed>,1:CaptureRecord,2:array<string,mixed>} */
    private function fixture(): array
    {
        $capture = new CaptureRecord(UuidCodec::newV7(), 'media-enrichment', hash('sha256', 'capture'), 'ASSETS_STORED', 'IN_PROGRESS');
        $mediaId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $binding = ['media_ref' => ['media_id' => $mediaId], 'target' => ['type' => 'classification', 'id' => $targetId], 'role' => 'representative', 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED'];
        $entry = ['media_id' => $mediaId, 'target' => $binding['target'], 'role' => 'representative', 'selection_source' => 'USER_EXPLICIT', 'selection_policy' => 'PINNED'];
        return [[
            'approved' => true, 'environment' => 'staging', 'capture_id' => $capture->captureId,
            'capture_fingerprint' => $capture->requestFingerprint, 'operation_family' => 'media_usage_reconciliation',
            'entity_type' => 'media', 'operation' => 'representative_bind', 'writer' => 'canonical_media_binding',
            'entrypoint' => 'nhk.capture.ingest', 'intent' => 'MEDIA_ENRICHMENT', 'media_ids' => [$mediaId],
            'target' => $binding['target'], 'bindings' => [$entry],
        ], $capture, ['intent' => 'MEDIA_ENRICHMENT', 'media_bindings' => [$binding]]];
    }
}
