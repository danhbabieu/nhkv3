<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\VideoStagingAdmission;
use NHK\Core\Application\Governance\StagingAcceptanceScopeVerifier;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

final class VideoStagingAdmissionTest extends TestCase
{
    public function test_exact_w64_binding_is_admitted(): void
    {
        [$capture, $scope] = $this->fixture();
        self::assertTrue((new VideoStagingAdmission())(false, $scope, $capture, [], []));
    }

    public function test_capture_video_variant_operation_revision_and_fingerprint_are_exact(): void
    {
        [$capture, $scope] = $this->fixture();
        $cases = [
            ['capture_id', 'different'], ['target_uuid', 'different'], ['subject_id', 'different'],
            ['operation', 'create'], ['expected_revision', 6], ['capture_fingerprint', hash('sha256', 'wrong')],
        ];
        foreach ($cases as [$field, $value]) {
            $candidate = $scope;
            if ($field === 'subject_id') {
                $assets = $capture->assets;
                $assets[0]['video_proposal']['payload']['metadata']['subject_resolution_packet']['id'] = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
                $candidate = new CaptureRecord($capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint, $capture->stage, $capture->status, $capture->articleId, $capture->articleStateToken, $assets, $capture->context, $capture->diagnostics, $capture->phaseReceipts, $capture->revision, $capture->createdAt, $capture->updatedAt);
                self::assertFalse((new VideoStagingAdmission())(false, $scope, $candidate, [], []));
                continue;
            }
            $candidate[$field] = $value;
            self::assertFalse((new VideoStagingAdmission())(false, $candidate, $capture, [], []), $field);
        }
    }

    public function test_unrelated_staging_package_and_production_are_rejected(): void
    {
        [$capture, $scope] = $this->fixture();
        $unrelated = $scope;
        $unrelated['target_uuid'] = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        self::assertFalse((new VideoStagingAdmission())(false, $unrelated, $capture, [], []));

        $verifier = new StagingAcceptanceScopeVerifier(static fn (): string => 'production', 'test-secret', new VideoStagingAdmission());
        $this->expectExceptionMessage('STAGING_PRODUCTION_FORBIDDEN');
        $verifier->issueForVideoPlan($capture, ['entity_type' => 'video', 'operation' => 'update', 'target_uuid' => $scope['target_uuid'], 'expected_revision' => 5, 'fingerprint' => hash('sha256', 'plan')]);
    }

    /** @return array{0:CaptureRecord,1:array<string,mixed>} */
    private function fixture(): array
    {
        $captureId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $fingerprint = hash('sha256', 'generic-video');
        $videoId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $subjectId = \NHK\Core\Shared\Uuid\UuidCodec::newV7();
        $capture = new CaptureRecord($captureId, 'generic-video', $fingerprint, 'SEMANTICS_RECONCILED', 'FAILED_RETRYABLE', null, null, [[
            'kind' => 'video', 'video_proposal' => ['payload' => ['canonical_id' => $videoId, 'expected_revision' => 5, 'metadata' => ['subject_resolution_packet' => ['id' => $subjectId, 'type' => 'variant']]]],
        ]], [], [], []);
        return [$capture, [
            'approved' => true, 'environment' => 'staging', 'operation_family' => 'governed_video_plan',
            'entity_type' => 'video', 'operation' => 'update', 'writer' => 'canonical_governed',
            'entrypoint' => 'nhk.capture.ingest', 'capture_id' => $captureId,
            'capture_fingerprint' => $fingerprint, 'target_uuid' => $videoId, 'subject_id' => $subjectId,
            'expected_revision' => 5,
        ]];
    }

}
