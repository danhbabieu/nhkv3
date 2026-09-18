<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\VideoW64StagingAdmission;
use NHK\Core\Application\Governance\StagingAcceptanceScopeVerifier;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

final class VideoW64StagingAdmissionTest extends TestCase
{
    private const CAPTURE = '01a0b230-1f3a-7b9f-b569-fb67834ab579';
    private const FINGERPRINT = 'f66ae6b07e139655ef1b50fe0c876297c4c1873c151d6511ea84695c6916ec02';
    private const VIDEO = '01a0aaf8-2a84-7287-bbd8-70af4d5485e4';
    private const VARIANT = '24eaeba5-b5f9-420f-a2fe-50b1f2a6130f';

    public function test_exact_w64_binding_is_admitted(): void
    {
        [$capture, $scope] = $this->fixture();
        self::assertTrue((new VideoW64StagingAdmission())(false, $scope, $capture, [], []));
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
                $candidate = $this->captureWithVariant('different');
                self::assertFalse((new VideoW64StagingAdmission())(false, $scope, $candidate, [], []));
                continue;
            }
            $candidate[$field] = $value;
            self::assertFalse((new VideoW64StagingAdmission())(false, $candidate, $capture, [], []), $field);
        }
    }

    public function test_unrelated_staging_package_and_production_are_rejected(): void
    {
        [$capture, $scope] = $this->fixture();
        $unrelated = $scope;
        $unrelated['target_uuid'] = '01a0aaf8-2a84-7287-bbd8-70af4d5485e5';
        self::assertFalse((new VideoW64StagingAdmission())(false, $unrelated, $capture, [], []));

        $verifier = new StagingAcceptanceScopeVerifier(static fn (): string => 'production', 'test-secret', new VideoW64StagingAdmission());
        $this->expectExceptionMessage('STAGING_PRODUCTION_FORBIDDEN');
        $verifier->issueForVideoPlan($capture, ['entity_type' => 'video', 'operation' => 'update', 'target_uuid' => self::VIDEO, 'expected_revision' => 5, 'fingerprint' => hash('sha256', 'plan')]);
    }

    /** @return array{0:CaptureRecord,1:array<string,mixed>} */
    private function fixture(): array
    {
        $capture = $this->captureWithVariant(self::VARIANT);
        return [$capture, [
            'approved' => true, 'environment' => 'staging', 'operation_family' => 'governed_video_plan',
            'entity_type' => 'video', 'operation' => 'update', 'writer' => 'canonical_governed',
            'entrypoint' => 'nhk.capture.ingest', 'capture_id' => self::CAPTURE,
            'capture_fingerprint' => self::FINGERPRINT, 'target_uuid' => self::VIDEO,
            'expected_revision' => 5,
        ]];
    }

    private function captureWithVariant(string $variant): CaptureRecord
    {
        return new CaptureRecord(self::CAPTURE, 'w64-clean', self::FINGERPRINT, 'SEMANTICS_RECONCILED', 'FAILED_RETRYABLE', null, null, [[
            'kind' => 'video', 'video_proposal' => ['payload' => ['canonical_id' => self::VIDEO, 'metadata' => ['subject_resolution_packet' => ['id' => $variant, 'type' => 'variant']]]],
        ]], [], [], []);
    }
}
