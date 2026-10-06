<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CapturePhaseReceiptReducer;
use PHPUnit\Framework\TestCase;

final class CapturePhaseReceiptReducerTest extends TestCase
{
    public function test_latest_normalizes_legacy_receipt_without_attempts(): void
    {
        $receipt = [
            'status' => 'FAILED',
            'result' => 'FAILED_RETRYABLE',
            'failure_code' => 'ERROR_X',
        ];

        $latest = CapturePhaseReceiptReducer::latest($receipt);

        self::assertSame(1, $latest['attempt_no']);
        self::assertSame('legacy:1', $latest['attempt_id']);
        self::assertSame('ERROR_X', $latest['failure_code']);
        self::assertSame('CURRENT', $latest['current_outcome']);
    }

    public function test_current_failure_codes_use_only_latest_failed_attempts(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED',
            'result' => 'FAILED_RETRYABLE',
            'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'COMPLETED',
            'result' => 'RECOVERED',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_Y', [
            'status' => 'FAILED',
            'result' => 'FAILED_RETRYABLE',
            'failure_code' => 'ERROR_Y',
        ]);

        self::assertSame(['ERROR_Y'], CapturePhaseReceiptReducer::currentFailureCodes($receipts));
        self::assertSame(2, count($receipts['PHASE_X']['attempts']));
        self::assertSame('ERROR_X', $receipts['PHASE_X']['attempts'][0]['failure_code']);
    }

    public function test_completed_latest_attempt_exposes_prior_failure_as_superseded(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED',
            'result' => 'FAILED_RETRYABLE',
            'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'COMPLETED',
            'result' => 'RECOVERED',
        ]);

        self::assertSame([], CapturePhaseReceiptReducer::currentFailureCodes($receipts));
        self::assertSame(['ERROR_X'], CapturePhaseReceiptReducer::supersededFailureCodes($receipts));
        self::assertCount(2, $receipts['PHASE_X']['attempts']);
    }

    public function test_same_failure_on_latest_retry_remains_current(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED',
            'result' => 'FAILED_RETRYABLE',
            'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'FAILED',
            'result' => 'FAILED_RETRYABLE',
            'failure_code' => 'ERROR_X',
        ]);

        self::assertSame(['ERROR_X'], CapturePhaseReceiptReducer::currentFailureCodes($receipts));
        self::assertSame(['ERROR_X'], CapturePhaseReceiptReducer::supersededFailureCodes($receipts));
    }

    public function test_different_latest_failure_replaces_prior_code_but_preserves_history(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED',
            'result' => 'FAILED_RETRYABLE',
            'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'FAILED',
            'result' => 'FAILED_RETRYABLE',
            'failure_code' => 'ERROR_Y',
        ]);

        self::assertSame(['ERROR_Y'], CapturePhaseReceiptReducer::currentFailureCodes($receipts));
        self::assertSame(['ERROR_X'], CapturePhaseReceiptReducer::supersededFailureCodes($receipts));
        self::assertSame('ERROR_X', $receipts['PHASE_X']['attempts'][0]['failure_code']);
        self::assertSame('ERROR_Y', $receipts['PHASE_X']['attempts'][1]['failure_code']);
    }

    public function test_append_preserves_historical_failure_and_exposes_verified_latest_outcome(): void
    {
        $failed = CapturePhaseReceiptReducer::append([], 'MEDIA_RECONCILED', [
            'status' => 'FAILED',
            'result' => 'FAILED_RETRYABLE',
            'failure_code' => 'MEDIA_ASSET_STORAGE_KEY_IS_ALREADY_BOUND_TO_DIFFERENT_CONTENT_',
        ]);
        $recovered = CapturePhaseReceiptReducer::append($failed, 'MEDIA_RECONCILED', [
            'status' => 'COMPLETED',
            'result' => 'REUSED_VERIFIED',
            'canonical_readback' => ['canonical_id' => '11111111-1111-4111-8111-111111111111'],
        ]);

        self::assertCount(2, $recovered['MEDIA_RECONCILED']['attempts']);
        self::assertSame('FAILED_RETRYABLE', $recovered['MEDIA_RECONCILED']['attempts'][0]['result']);
        self::assertSame('REUSED_VERIFIED', $recovered['MEDIA_RECONCILED']['latest']['result']);
        self::assertSame('CURRENT', $recovered['MEDIA_RECONCILED']['current_outcome']);
        self::assertSame('MEDIA_ASSET_STORAGE_KEY_IS_ALREADY_BOUND_TO_DIFFERENT_CONTENT_', $recovered['MEDIA_RECONCILED']['attempts'][0]['failure_code']);
        self::assertContains('MEDIA_ASSET_STORAGE_KEY_IS_ALREADY_BOUND_TO_DIFFERENT_CONTENT_', $recovered['MEDIA_RECONCILED']['latest']['superseded_failure_codes']);
    }

    public function test_latest_unresolved_failure_remains_current_even_after_another_phase_succeeds(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'MEDIA_RECONCILED', [
            'status' => 'COMPLETED', 'result' => 'REUSED_VERIFIED',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'SEMANTICS_RECONCILED', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'SEMANTIC_READBACK_UNAVAILABLE',
        ]);

        self::assertSame('REUSED_VERIFIED', $receipts['MEDIA_RECONCILED']['latest']['result']);
        self::assertSame('FAILED_RETRYABLE', $receipts['SEMANTICS_RECONCILED']['latest']['result']);
        self::assertSame('SEMANTIC_READBACK_UNAVAILABLE', $receipts['SEMANTICS_RECONCILED']['latest']['failure_code']);
    }
}
