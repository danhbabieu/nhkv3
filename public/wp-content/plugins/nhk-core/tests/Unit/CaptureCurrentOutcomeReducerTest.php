<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CaptureCurrentOutcomeReducer;
use NHK\Core\Domain\Capture\{CaptureRecord, CaptureStage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CaptureCurrentOutcomeReducerTest extends TestCase
{
    public function test_verified_latest_phase_clears_historical_failure_from_current_code(): void
    {
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'current-outcome', hash('sha256', 'current-outcome'), CaptureStage::READY_FOR_PUBLICATION->value, 'COMPLETE', 123, 'state-current', [], [],
            ['failure_history' => [['code' => 'MEDIA_ASSET_STORAGE_KEY_IS_ALREADY_BOUND_TO_DIFFERENT_CONTENT_']], 'completion' => ['complete' => true, 'blockers' => []]],
            ['MEDIA_RECONCILED' => ['status' => 'COMPLETED', 'result' => 'REUSED_VERIFIED', 'latest' => ['status' => 'COMPLETED', 'result' => 'REUSED_VERIFIED']]],
        );

        self::assertNull(CaptureCurrentOutcomeReducer::failureCode($capture));
        self::assertSame('MEDIA_ASSET_STORAGE_KEY_IS_ALREADY_BOUND_TO_DIFFERENT_CONTENT_', $capture->diagnostics['failure_history'][0]['code']);
    }

    public function test_current_failed_required_phase_remains_the_current_code(): void
    {
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'current-failure', hash('sha256', 'current-failure'), CaptureStage::SEMANTICS_RECONCILED->value, 'PARTIAL', null, null, [], [],
            ['completion' => ['complete' => false, 'blockers' => []]],
            ['SEMANTICS_RECONCILED' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'latest' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'SEMANTIC_READBACK_UNAVAILABLE']]],
        );

        self::assertSame('SEMANTIC_READBACK_UNAVAILABLE', CaptureCurrentOutcomeReducer::failureCode($capture));
    }

    public function test_unresolved_video_category_is_not_retryable_when_only_owner_is_missing(): void
    {
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'category-review', hash('sha256', 'category-review'), CaptureStage::SEMANTICS_RECONCILED->value, 'REVIEW_REQUIRED', null, null, [], [],
            ['completion' => [
                'status' => 'REVIEW_REQUIRED',
                'blockers' => ['CATEGORY_UNRESOLVED'],
                'missing_required_owners' => [['owner_type' => 'video', 'owner_id' => UuidCodec::newV7()]],
                'resume_hints' => ['resume_children' => ['video']],
            ]],
            ['VIDEO_GOVERNANCE' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'latest' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CATEGORY_UNRESOLVED']]],
        );

        self::assertSame(['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'], CaptureCurrentOutcomeReducer::retryEligibility($capture, ['resume_children' => ['video']]));
    }
}
