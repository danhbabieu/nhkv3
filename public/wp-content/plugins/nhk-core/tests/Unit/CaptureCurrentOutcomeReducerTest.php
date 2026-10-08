<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticleReviewFreshness;
use NHK\Core\Application\Capture\{CaptureCurrentOutcomeReducer, CaptureDecisionDependencyFingerprint, CapturePhaseReceiptReducer};
use NHK\Core\Domain\Capture\{CaptureRecord, CaptureStage};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CaptureCurrentOutcomeReducerTest extends TestCase
{
    public function test_reconciliation_does_not_invent_failure_record_for_completion_only_blocker(): void
    {
        $diagnostics = ['completion' => ['status' => 'PARTIAL', 'blockers' => ['OWNER_PUBLICATION_REQUIRED']]];

        $reconciled = CaptureCurrentOutcomeReducer::reconcileDiagnostics($diagnostics, []);

        self::assertArrayNotHasKey('failure', $reconciled);
        self::assertSame(['OWNER_PUBLICATION_REQUIRED'], $reconciled['completion']['blockers']);
    }

    public function test_reevaluable_knowledge_review_is_not_reported_as_terminal_when_retry_is_eligible(): void
    {
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'knowledge-review-retry', hash('sha256', 'knowledge-review-retry'),
            CaptureStage::SEMANTICS_RECONCILED->value, 'REVIEW_REQUIRED', null, null, [],
            ['raw_input' => 'Westminster Quarters được ghi nhận.', 'content_intent' => ['intent' => 'KNOWLEDGE_DELTA']],
            ['completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED', 'REQUIRED_OWNER_READBACK_UNVERIFIED']]],
            ['SEMANTICS_RECONCILED' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED']],
        );

        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
        self::assertSame(['KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED', 'REQUIRED_OWNER_READBACK_UNVERIFIED'], CaptureCurrentOutcomeReducer::currentBlockers($capture->diagnostics, $capture->phaseReceipts, $capture));
        self::assertSame('RECOVERABLE_INTERRUPTED', CaptureCurrentOutcomeReducer::lifecycleState($capture));
    }

    public function test_failed_retryable_x_then_success_makes_x_historical_only(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'COMPLETED', 'result' => 'RECOVERED',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame([], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertNull(CaptureCurrentOutcomeReducer::failureCode($capture));

        $reconciled = CaptureCurrentOutcomeReducer::reconcileDiagnostics($diagnostics, $receipts);
        self::assertSame([], $reconciled['completion']['blockers']);
        self::assertSame('ERROR_X', $reconciled['failure_history'][0]['code']);
    }

    public function test_failed_retryable_x_then_x_keeps_x_current(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame(['ERROR_X'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame('ERROR_X', CaptureCurrentOutcomeReducer::failureCode($capture));
        self::assertArrayNotHasKey('failure_history', CaptureCurrentOutcomeReducer::reconcileDiagnostics($diagnostics, $receipts));
    }

    public function test_failed_retryable_x_then_y_uses_y_current_and_preserves_x_history(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_Y',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame(['ERROR_Y'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame('ERROR_Y', CaptureCurrentOutcomeReducer::failureCode($capture));

        $reconciled = CaptureCurrentOutcomeReducer::reconcileDiagnostics($diagnostics, $receipts);
        self::assertSame(['ERROR_Y'], $reconciled['completion']['blockers']);
        self::assertSame('ERROR_X', $reconciled['failure_history'][0]['code']);
    }

    public function test_later_successful_phase_does_not_resurrect_superseded_failure(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'COMPLETED', 'result' => 'RECOVERED',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_Y', [
            'status' => 'COMPLETED', 'result' => 'VERIFIED',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame([], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertNull(CaptureCurrentOutcomeReducer::failureCode($capture));
    }

    public function test_current_owner_review_remains_blocking_after_historical_failure_is_superseded(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'COMPLETED', 'result' => 'RECOVERED',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'OWNER_REVIEW', [
            'status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'OWNER_REVIEW_REQUIRED',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X', 'OWNER_REVIEW_REQUIRED']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame(['OWNER_REVIEW_REQUIRED'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame('OWNER_REVIEW_REQUIRED', CaptureCurrentOutcomeReducer::failureCode($capture));
    }

    public function test_current_system_blocked_failure_remains_authoritative(): void
    {
        $receipts = CapturePhaseReceiptReducer::append([], 'PHASE_X', [
            'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'PHASE_X', [
            'status' => 'COMPLETED', 'result' => 'RECOVERED',
        ]);
        $receipts = CapturePhaseReceiptReducer::append($receipts, 'SYSTEM_GATE', [
            'status' => 'BLOCKED', 'result' => 'BLOCKED', 'failure_code' => 'SYSTEM_BLOCKED',
        ]);
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['blockers' => ['ERROR_X', 'SYSTEM_BLOCKED']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame(['SYSTEM_BLOCKED'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame('SYSTEM_BLOCKED', CaptureCurrentOutcomeReducer::failureCode($capture));
    }

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

    public function test_historical_failure_is_not_current_after_new_phase_attempt_completes(): void
    {
        $capture = new CaptureRecord(
            UuidCodec::newV7(), 'historical-then-complete', hash('sha256', 'historical-then-complete'), CaptureStage::SEMANTICS_RECONCILED->value, 'COMPLETE', null, null, [], [],
            ['completion' => ['complete' => true, 'blockers' => []]],
            ['CONTENT_PREPARATION' => [
                'status' => 'COMPLETED',
                'result' => 'VERIFIED',
                'latest' => ['status' => 'COMPLETED', 'result' => 'VERIFIED', 'current_outcome' => 'CURRENT'],
                'attempts' => [
                    ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'VIDEO_EDITORIAL_QUALITY_BLOCKED'],
                    ['status' => 'COMPLETED', 'result' => 'VERIFIED'],
                ],
            ]],
        );

        self::assertNull(CaptureCurrentOutcomeReducer::failureCode($capture));
    }

    public function test_legacy_retryable_failure_is_superseded_by_later_successful_phases(): void
    {
        $receipts = [
            'INTERPRETED' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
            'CONTENT_PREPARATION' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
            'FAILED_RETRYABLE' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'current_outcome' => 'CURRENT'],
            'MEDIA_ADOPTED' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
            'SUBJECTS_RESOLVED' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
            'ENRICHMENT_PLANNING_ENVELOPE' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
            'SEMANTICS_RECONCILED_FINAL' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
        ];
        $diagnostics = [
            'failure' => ['code' => 'CAPTURE_UTF8_INVALID', 'classification' => 'FAILED_RETRYABLE'],
            'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['CAPTURE_UTF8_INVALID']],
        ];
        $capture = $this->capture($diagnostics, $receipts, 'REVIEW_REQUIRED');

        self::assertSame([], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertNull(CaptureCurrentOutcomeReducer::failureCode($capture));
        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
        self::assertContains('CAPTURE_UTF8_INVALID', array_column(CaptureCurrentOutcomeReducer::reconcileDiagnostics($diagnostics, $receipts)['failure_history'], 'code'));
    }

    public function test_legacy_article_pre_create_review_does_not_reassert_an_inherited_retryable_failure(): void
    {
        $receipts = [
            'FAILED_RETRYABLE' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X', 'current_outcome' => 'CURRENT'],
            'INTERPRETED' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
            'CONTENT_PREPARATION' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
            'ARTICLE_PRE_CREATE_REVIEW' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'ERROR_X', 'current_outcome' => 'CURRENT'],
        ];
        $diagnostics = [
            'failure' => ['code' => 'ERROR_X'],
            'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['ERROR_X']],
        ];
        $capture = $this->capture($diagnostics, $receipts, 'REVIEW_REQUIRED');

        self::assertSame([], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
    }

    public function test_current_article_pre_create_review_attempt_remains_a_blocker(): void
    {
        $receipts = [
            'FAILED_RETRYABLE' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X'],
            'INTERPRETED' => ['status' => 'COMPLETED', 'result' => 'COMPLETED'],
            'ARTICLE_PRE_CREATE_REVIEW' => [
                'attempts' => [['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'ARTICLE_SUBSTANTIAL_OVERLAP']],
                'latest' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'ARTICLE_SUBSTANTIAL_OVERLAP'],
            ],
        ];
        $diagnostics = ['failure' => ['code' => 'ARTICLE_SUBSTANTIAL_OVERLAP'], 'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['ARTICLE_SUBSTANTIAL_OVERLAP']]];

        self::assertSame(['ARTICLE_SUBSTANTIAL_OVERLAP'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
    }

    public function test_live_shaped_inherited_article_review_is_reevaluable_even_when_completion_is_blocked(): void
    {
        $receipts = [
            'FAILED_RETRYABLE' => [
                'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'CAPTURE_UTF8_INVALID',
                'attempt_no' => 1, 'attempt_id' => 'FAILED_RETRYABLE:1',
                'attempts' => [['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'attempt_no' => 1]],
                'latest' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'attempt_no' => 1],
            ],
            'INTERPRETED' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS', 'attempts' => [['status' => 'COMPLETED', 'result' => 'IN_PROGRESS']]],
            'CONTENT_PREPARATION' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS', 'attempts' => [['status' => 'COMPLETED', 'result' => 'IN_PROGRESS']]],
            'SEMANTICS_RECONCILED' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS', 'attempts' => [['status' => 'COMPLETED', 'result' => 'IN_PROGRESS']]],
            'ARTICLE_PRE_CREATE_REVIEW' => [
                'status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CAPTURE_UTF8_INVALID',
                'attempt_no' => 1, 'attempt_id' => 'ARTICLE_PRE_CREATE_REVIEW:1',
                'attempts' => [['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'attempt_no' => 1]],
                'latest' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'attempt_no' => 1],
            ],
        ];
        $diagnostics = [
            'failure' => ['code' => 'CAPTURE_UTF8_INVALID', 'classification' => 'FAILED_RETRYABLE'],
            'completion' => ['status' => 'BLOCKED', 'blockers' => ['CAPTURE_UTF8_INVALID', 'CANONICAL_READBACK_UNVERIFIED']],
        ];
        $capture = $this->capture($diagnostics, $receipts, 'REVIEW_REQUIRED');

        self::assertSame(['CANONICAL_READBACK_UNVERIFIED'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
    }

    public function test_persisted_live_capture_shape_reopens_article_pre_create_review_after_hydration_state_is_recomputed(): void
    {
        $receipts = [
            'FAILED_RETRYABLE' => [
                'status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'CAPTURE_UTF8_INVALID',
                'attempt_no' => 1, 'attempts' => [['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'attempt_no' => 1]],
                'latest' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'attempt_no' => 1],
            ],
            'INTERPRETED' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS', 'attempts' => [['status' => 'COMPLETED', 'result' => 'IN_PROGRESS']]],
            'CONTENT_PREPARATION' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS', 'attempts' => [['status' => 'COMPLETED', 'result' => 'IN_PROGRESS']]],
            'SEMANTICS_RECONCILED' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS', 'attempts' => [['status' => 'COMPLETED', 'result' => 'IN_PROGRESS']]],
            'ARTICLE_PRE_CREATE_REVIEW' => [
                'status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CANONICAL_READBACK_UNVERIFIED',
                'attempt_no' => 2, 'attempts' => [
                    ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CAPTURE_UTF8_INVALID', 'attempt_no' => 1],
                    ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CANONICAL_READBACK_UNVERIFIED', 'attempt_no' => 2, 'superseded_failure_codes' => ['CAPTURE_UTF8_INVALID']],
                ],
                'latest' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'CANONICAL_READBACK_UNVERIFIED', 'attempt_no' => 2],
            ],
        ];
        $capture = new CaptureRecord(
            '01a1105c-4cc6-7529-ad3e-6bc652b4ae7f', 'live-capture-retry', hash('sha256', 'live-capture-retry'),
            CaptureStage::SEMANTICS_RECONCILED->value, 'REVIEW_REQUIRED', null, null, [],
            ['content_intent' => ['intent' => 'TEXT_ARTICLE', 'article_required' => true], 'subject_resolution_packet' => [
                'status' => 'resolved', 'canonical_subject_id' => '984658bf-19a6-4daa-a220-2a6c13af81ed', 'entity_type' => 'model', 'revision' => 1,
            ]],
            [
                'failure' => ['code' => 'CANONICAL_READBACK_UNVERIFIED', 'classification' => 'FAILED_RETRYABLE', 'message' => 'UTF8_INVALID_INPUT'],
                'completion' => ['status' => 'BLOCKED', 'canonical_readback_verified' => false, 'blockers' => ['CANONICAL_READBACK_UNVERIFIED']],
                'article_resolution' => ['research' => ['ready_for_draft' => true, 'blockers' => [], 'warnings' => ['MEDIA_PLACEHOLDER_OR_UNAVAILABLE']]],
            ],
            $receipts, 23,
        );

        self::assertSame([], CaptureCurrentOutcomeReducer::currentBlockers($capture->diagnostics, $capture->phaseReceipts));
        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
        $effective = CaptureCurrentOutcomeReducer::effectiveCapture($capture);
        self::assertArrayNotHasKey('failure', $effective->diagnostics);
        self::assertContains('CANONICAL_READBACK_UNVERIFIED', array_column($effective->diagnostics['failure_history'], 'code'));
    }

    public function test_legacy_article_pre_create_review_is_reevaluable_after_failure_is_superseded(): void
    {
        $receipts = [
            'ARTICLE_PRE_CREATE_REVIEW' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'LEGACY_FAILURE', 'current_outcome' => 'CURRENT'],
            'INTERPRETED' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
        ];
        $diagnostics = ['failure' => ['code' => 'LEGACY_FAILURE'], 'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['LEGACY_FAILURE']]];
        $capture = $this->capture($diagnostics, $receipts, 'REVIEW_REQUIRED');

        self::assertSame([], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
    }

    public function test_legacy_retryable_failure_without_recovery_remains_current(): void
    {
        $receipts = ['PHASE_X' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X', 'current_outcome' => 'CURRENT']];
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['status' => 'PARTIAL', 'blockers' => ['ERROR_X']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame(['ERROR_X'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame('ERROR_X', CaptureCurrentOutcomeReducer::failureCode($capture));
    }

    public function test_legacy_retryable_failure_is_historical_when_followed_by_another_failure(): void
    {
        $receipts = [
            'PHASE_X' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X', 'current_outcome' => 'CURRENT'],
            'PHASE_Y' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_Y', 'current_outcome' => 'CURRENT'],
        ];
        $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['status' => 'PARTIAL', 'blockers' => ['ERROR_X']]];
        $capture = $this->capture($diagnostics, $receipts);

        self::assertSame(['ERROR_Y'], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
        self::assertSame('ERROR_Y', CaptureCurrentOutcomeReducer::failureCode($capture));
    }

    public function test_legacy_retryable_failure_does_not_override_owner_or_system_blocker(): void
    {
        foreach (['OWNER_REVIEW_REQUIRED' => 'REVIEW_REQUIRED', 'SYSTEM_BLOCKED' => 'BLOCKED'] as $code => $status) {
            $receipts = [
                'PHASE_X' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'ERROR_X', 'current_outcome' => 'CURRENT'],
                'CURRENT_GATE' => ['status' => $status, 'result' => $status, 'failure_code' => $code, 'current_outcome' => 'CURRENT'],
            ];
            $diagnostics = ['failure' => ['code' => 'ERROR_X'], 'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['ERROR_X', $code]]];
            $capture = $this->capture($diagnostics, $receipts);

            self::assertSame([$code], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
            self::assertSame($code, CaptureCurrentOutcomeReducer::failureCode($capture));
        }
    }

    public function test_legacy_non_retryable_and_governance_failures_remain_current_after_later_success(): void
    {
        foreach (['NON_RETRYABLE' => 'FAILED', 'CAPTURE_GOVERNANCE_FAILED' => 'FAILED_RETRYABLE'] as $code => $result) {
            $receipts = [
                'PHASE_X' => ['status' => 'FAILED', 'result' => $result, 'failure_code' => $code, 'current_outcome' => 'CURRENT'],
                'PHASE_Y' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
            ];
            $diagnostics = ['failure' => ['code' => $code], 'completion' => ['status' => 'PARTIAL', 'blockers' => [$code]]];
            $capture = $this->capture($diagnostics, $receipts);

            self::assertSame([$code], CaptureCurrentOutcomeReducer::currentBlockers($diagnostics, $receipts));
            self::assertSame($code, CaptureCurrentOutcomeReducer::failureCode($capture));
        }

        $receipts = [
            'PHASE_X' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'HUMAN_REVIEW_REQUIRED', 'current_outcome' => 'CURRENT'],
            'PHASE_Y' => ['status' => 'COMPLETED', 'result' => 'COMPLETED', 'current_outcome' => 'CURRENT'],
        ];
        $capture = $this->capture(['failure' => ['code' => 'HUMAN_REVIEW_REQUIRED'], 'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['HUMAN_REVIEW_REQUIRED']]], $receipts);
        self::assertSame(['HUMAN_REVIEW_REQUIRED'], CaptureCurrentOutcomeReducer::currentBlockers($capture->diagnostics, $receipts));
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

    public function test_legacy_review_without_dependency_fingerprint_gets_one_bounded_reevaluation(): void
    {
        $capture = $this->reviewCapture(['content_preparation' => ['quality_decision' => 'READY']]);

        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
    }

    public function test_interrupted_in_progress_capture_with_no_active_phase_is_resumable(): void
    {
        $capture = $this->capture([
            'failure' => ['code' => 'SUBSTANTIAL_OVERLAP'],
            'completion' => ['status' => 'BLOCKED', 'blockers' => ['SUBSTANTIAL_OVERLAP']],
            'article_resolution' => ['research' => ['ready_for_draft' => true, 'blockers' => [], 'overlap_analysis' => ['classification' => 'SUBSTANTIAL_OVERLAP', 'candidates' => []]]],
        ], [
            'INTERPRETED' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS'],
            'CONTENT_PREPARATION' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS'],
            'SEMANTICS_RECONCILED' => ['status' => 'COMPLETED', 'result' => 'IN_PROGRESS'],
            'ARTICLE_PRE_CREATE_REVIEW' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'SUBSTANTIAL_OVERLAP'],
        ], 'IN_PROGRESS');

        self::assertSame('RECOVERABLE_INTERRUPTED', CaptureCurrentOutcomeReducer::lifecycleState($capture));
        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
    }

    public function test_fresh_started_phase_denies_duplicate_execution(): void
    {
        $capture = $this->capture(
            ['completion' => ['status' => 'PARTIAL', 'blockers' => []]],
            ['SEMANTICS_RECONCILED' => ['status' => 'STARTED', 'result' => 'IN_PROGRESS', 'started_at' => gmdate('c'), 'completed_at' => null]],
            'IN_PROGRESS',
        );

        self::assertSame('ACTIVELY_EXECUTING', CaptureCurrentOutcomeReducer::lifecycleState($capture));
        self::assertSame(['eligible' => false, 'reason' => 'CAPTURE_EXECUTION_IN_PROGRESS'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
    }

    public function test_review_with_same_dependency_fingerprint_is_active_and_not_retryable(): void
    {
        $capture = $this->reviewCapture(['content_preparation' => ['quality_decision' => 'READY']]);
        $fingerprint = CaptureDecisionDependencyFingerprint::current($capture);
        $persisted = new CaptureRecord(
            $capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint, $capture->stage, $capture->status,
            $capture->articleId, $capture->articleStateToken, $capture->assets, $capture->context,
            $capture->diagnostics + ['decision_dependency_fingerprint' => $fingerprint], $capture->phaseReceipts,
        );

        self::assertSame(['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'], CaptureCurrentOutcomeReducer::retryEligibility($persisted));
    }

    public function test_changed_canonical_dependency_revision_reopens_review(): void
    {
        $capture = $this->reviewCapture([
            'canonical_context' => [['id' => 'claim-1', 'revision' => 1]],
            'content_preparation' => ['quality_decision' => 'READY'],
        ]);
        $fingerprint = CaptureDecisionDependencyFingerprint::current($capture);
        $changed = new CaptureRecord(
            $capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint, $capture->stage, $capture->status,
            $capture->articleId, $capture->articleStateToken, $capture->assets,
            $capture->context + ['canonical_context' => [['id' => 'claim-1', 'revision' => 2]]],
            $capture->diagnostics + ['decision_dependency_fingerprint' => $fingerprint], $capture->phaseReceipts,
        );

        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($changed));
    }

    public function test_hard_block_review_cannot_be_reopened_by_dependency_change(): void
    {
        $capture = $this->reviewCapture([
            'content_preparation' => ['quality_decision' => 'HARD_BLOCK', 'blockers' => ['HARD_BLOCK']],
        ]);

        self::assertSame(['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
    }

    public function test_current_substantial_overlap_review_stays_current_and_is_not_stale_retryable(): void
    {
        $capture = $this->capture([
            'failure' => ['code' => 'SUBSTANTIAL_OVERLAP'],
            'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['SUBSTANTIAL_OVERLAP']],
            'article_resolution' => ['research' => ['ready_for_draft' => true, 'blockers' => [], 'overlap_analysis' => ['classification' => 'SUBSTANTIAL_OVERLAP', 'candidates' => [['article_id' => 902, 'post_id' => 902, 'classification' => 'SAME_INTENT', 'matched_dimensions' => ['primary_subject'], 'reason' => 'same persisted editorial intent']]]]],
        ], [
            'ARTICLE_PRE_CREATE_REVIEW' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'SUBSTANTIAL_OVERLAP', 'current_outcome' => 'CURRENT'],
        ], 'REVIEW_REQUIRED');
        $capture = $this->withArticleReviewProvenance($capture);

        self::assertSame(['902'], $capture->diagnostics['article_review_provenance']['candidate_ids']);
        self::assertSame('SAME_INTENT', $capture->diagnostics['article_review_provenance']['candidates'][0]['classification']);
        self::assertSame(['primary_subject'], $capture->diagnostics['article_review_provenance']['candidates'][0]['matched_dimensions']);
        self::assertSame(['SUBSTANTIAL_OVERLAP'], CaptureCurrentOutcomeReducer::currentBlockers($capture->diagnostics, $capture->phaseReceipts));
        self::assertSame(['eligible' => false, 'reason' => 'CURRENT_REVIEW_REQUIRED'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
    }

    public function test_legacy_article_overlap_review_without_policy_metadata_is_reevaluable(): void
    {
        $capture = $this->capture([
            'failure' => ['code' => 'SUBSTANTIAL_OVERLAP'],
            'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['SUBSTANTIAL_OVERLAP']],
            'article_resolution' => ['research' => ['ready_for_draft' => true, 'blockers' => [], 'overlap_analysis' => ['classification' => 'SUBSTANTIAL_OVERLAP', 'candidates' => []]]],
        ], [
            'ARTICLE_PRE_CREATE_REVIEW' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'SUBSTANTIAL_OVERLAP', 'current_outcome' => 'CURRENT'],
        ], 'REVIEW_REQUIRED');

        self::assertSame([], CaptureCurrentOutcomeReducer::currentBlockers($capture->diagnostics, $capture->phaseReceipts));
        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($capture));
    }

    public function test_article_review_policy_change_reopens_a_persisted_overlap_review(): void
    {
        $capture = $this->withArticleReviewProvenance($this->capture([
            'failure' => ['code' => 'SUBSTANTIAL_OVERLAP'],
            'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => ['SUBSTANTIAL_OVERLAP']],
            'article_resolution' => ['research' => ['ready_for_draft' => true, 'blockers' => [], 'overlap_analysis' => ['classification' => 'SUBSTANTIAL_OVERLAP', 'candidates' => [['article_id' => 902]]]]],
        ], [
            'ARTICLE_PRE_CREATE_REVIEW' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'SUBSTANTIAL_OVERLAP'],
        ], 'REVIEW_REQUIRED'));
        $diagnostics = $capture->diagnostics;
        $diagnostics['article_review_provenance']['policy_fingerprint'] = 'obsolete-policy';
        $changed = new CaptureRecord(
            $capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint, $capture->stage, $capture->status,
            $capture->articleId, $capture->articleStateToken, $capture->assets, $capture->context, $diagnostics, $capture->phaseReceipts,
        );

        self::assertSame(['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'], CaptureCurrentOutcomeReducer::retryEligibility($changed));
    }

    private function withArticleReviewProvenance(CaptureRecord $capture): CaptureRecord
    {
        $diagnostics = $capture->diagnostics;
        $diagnostics['article_review_provenance'] = ArticleReviewFreshness::persistedMetadata($capture, $diagnostics);
        return new CaptureRecord(
            $capture->captureId, $capture->idempotencyKey, $capture->requestFingerprint, $capture->stage, $capture->status,
            $capture->articleId, $capture->articleStateToken, $capture->assets, $capture->context, $diagnostics, $capture->phaseReceipts,
        );
    }

    /** @param array<string,mixed> $diagnosticParts */
    private function reviewCapture(array $diagnosticParts): CaptureRecord
    {
        return new CaptureRecord(
            UuidCodec::newV7(), 'review-' . bin2hex(random_bytes(3)), hash('sha256', 'review-' . bin2hex(random_bytes(3))),
            CaptureStage::SEMANTICS_RECONCILED->value, 'REVIEW_REQUIRED', null, null, [],
            ['raw_input' => 'Short subject input.', 'content_intent' => ['intent' => 'VIDEO']],
            array_replace_recursive([
                'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => [], 'resume_hints' => []],
            ], $diagnosticParts),
            ['CONTENT_PREPARATION' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'latest' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'VIDEO_EDITORIAL_QUALITY_BLOCKED']]],
        );
    }

    /** @param array<string,mixed> $diagnostics @param array<string,mixed> $receipts */
    private function capture(array $diagnostics, array $receipts, string $status = 'PARTIAL'): CaptureRecord
    {
        return new CaptureRecord(
            UuidCodec::newV7(), 'current-state', hash('sha256', 'current-state'),
            CaptureStage::SEMANTICS_RECONCILED->value, $status, null, null, [], [],
            $diagnostics, $receipts,
        );
    }
}
