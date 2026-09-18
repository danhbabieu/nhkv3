<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CapturePhaseReceiptReducer;
use PHPUnit\Framework\TestCase;

final class CapturePhaseReceiptReducerTest extends TestCase
{
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
