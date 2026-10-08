<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Article\ArticleReviewFreshness;
use NHK\Core\Domain\Capture\CaptureRecord;

/** Derives current retry truth from latest phase outcomes and completion evidence. */
final class CaptureCurrentOutcomeReducer
{
    private const ACTIVE_EXECUTION_TTL_SECONDS = 900;

    /**
     * One lifecycle decision for Capture reads and continuation admission.
     * An unfinished phase receipt is the persisted execution signal; once it
     * is absent or expires, IN_PROGRESS is an interrupted checkpoint, not a
     * durable claim that a worker still owns the Capture.
     */
    public static function lifecycleState(CaptureRecord $capture, array $input = []): string
    {
        return self::currentDecision($capture, $input)['lifecycle_state'];
    }

    /**
     * Derive the current lifecycle, retry and blocker projection once.
     * Read models and continuation admission must not make independent policy
     * decisions from the same persisted Capture.
     *
     * @return array{lifecycle_state:string,retry:array{eligible:bool,reason:?string},blockers:list<string>}
     */
    public static function currentDecision(CaptureRecord $capture, array $input = []): array
    {
        if ($capture->status === 'COMPLETE' || $capture->status === 'PUBLISHED'
            || in_array($capture->stage, ['READY_FOR_PUBLICATION', 'PUBLISHED'], true)
            && (($capture->diagnostics['completion']['complete'] ?? false) === true)) {
            return [
                'lifecycle_state' => 'COMPLETED_CONVERGED',
                'retry' => ['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'],
                'blockers' => [],
            ];
        }
        if ($capture->status === 'IN_PROGRESS' && self::hasActiveExecution($capture)) {
            return [
                'lifecycle_state' => 'ACTIVELY_EXECUTING',
                'retry' => ['eligible' => false, 'reason' => 'CAPTURE_EXECUTION_IN_PROGRESS'],
                'blockers' => self::currentBlockers($capture->diagnostics, $capture->phaseReceipts, $capture, $input),
            ];
        }

        $blockers = self::currentBlockers($capture->diagnostics, $capture->phaseReceipts, $capture, $input);
        $lifecycleState = $blockers !== [] && !self::isReevaluatableReview($capture, $input, $blockers)
            ? 'TERMINALLY_BLOCKED'
            : 'RECOVERABLE_INTERRUPTED';
        $retry = ['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'];

        if ($capture->status === 'REVIEW_REQUIRED' && self::hasCurrentArticleOverlapReview($capture)) {
            return [
                'lifecycle_state' => $lifecycleState,
                'retry' => ['eligible' => false, 'reason' => 'CURRENT_REVIEW_REQUIRED'],
                'blockers' => $blockers,
            ];
        }
        if ($lifecycleState === 'TERMINALLY_BLOCKED') {
            return ['lifecycle_state' => $lifecycleState, 'retry' => $retry, 'blockers' => $blockers];
        }

        if (in_array($capture->stage, ['READY_FOR_PUBLICATION', 'PUBLISHED'], true)) {
            return ['lifecycle_state' => $lifecycleState, 'retry' => $retry, 'blockers' => $blockers];
        }

        $completion = is_array($capture->diagnostics['completion'] ?? null) ? $capture->diagnostics['completion'] : [];
        $completionBlockers = $blockers;
        if (in_array('CATEGORY_UNRESOLVED', $completionBlockers, true) || self::failureCode($capture) === 'CATEGORY_UNRESOLVED') {
            return ['lifecycle_state' => $lifecycleState, 'retry' => $retry, 'blockers' => $blockers];
        }
        if (in_array($capture->status, ['APPLIED', 'REVIEW_REQUIRED'], true)
            && is_array($input['subject_reconciliation'] ?? null)
            && ($input['subject_reconciliation']['confirmed'] ?? false) === true) {
            return [
                'lifecycle_state' => $lifecycleState,
                'retry' => ['eligible' => true, 'reason' => null],
                'blockers' => $blockers,
            ];
        }

        $persisted = trim((string) ($capture->diagnostics['decision_dependency_fingerprint'] ?? $capture->context['decision_dependency_fingerprint'] ?? ''));
        $current = CaptureDecisionDependencyFingerprint::current($capture, $input);
        if ($capture->status === 'FAILED_RETRYABLE') {
            if ($persisted !== '' && hash_equals($persisted, $current)) {
                return ['lifecycle_state' => $lifecycleState, 'retry' => $retry, 'blockers' => $blockers];
            }
            return [
                'lifecycle_state' => $lifecycleState,
                'retry' => ['eligible' => true, 'reason' => null],
                'blockers' => $blockers,
            ];
        }

        if (in_array($capture->status, ['REVIEW_REQUIRED', 'IN_PROGRESS'], true)
            && self::isReevaluatableReview($capture, $input, $blockers)) {
            return [
                'lifecycle_state' => $lifecycleState,
                'retry' => ['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'],
                'blockers' => $blockers,
            ];
        }
        if (!in_array(strtoupper(trim((string) ($completion['status'] ?? ''))), ['PARTIAL', 'REVIEW_REQUIRED'], true)) {
            return ['lifecycle_state' => $lifecycleState, 'retry' => $retry, 'blockers' => $blockers];
        }
        if ($capture->status === 'REVIEW_REQUIRED') {
            if (self::isHardBlockedReview($capture)) {
                return ['lifecycle_state' => $lifecycleState, 'retry' => $retry, 'blockers' => $blockers];
            }
            if ($persisted === '' || !hash_equals($persisted, $current)) {
                return [
                    'lifecycle_state' => $lifecycleState,
                    'retry' => ['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'],
                    'blockers' => $blockers,
                ];
            }
        }

        $hintPacket = is_array($capture->diagnostics['resume_hints'] ?? null)
            ? $capture->diagnostics['resume_hints']
            : (is_array($completion['resume_hints'] ?? null) ? $completion['resume_hints'] : []);
        $hints = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($hintPacket['resume_children'] ?? [])))));
        $requested = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($input['resume_children'] ?? [])))));
        if ($requested === []) $requested = $hints;
        if ($hints === [] && self::supportsCanonicalVideoCompletionRetry($capture)) $hints = ['video'];
        if ($requested === [] && self::supportsCanonicalVideoCompletionRetry($capture)) $requested = ['video'];
        if ($hints === [] || $requested === [] || array_diff($requested, $hints) !== []) {
            return ['lifecycle_state' => $lifecycleState, 'retry' => $retry, 'blockers' => $blockers];
        }
        if (self::supportsCanonicalVideoCompletionRetry($capture) && $requested === ['video']) {
            return [
                'lifecycle_state' => $lifecycleState,
                'retry' => ['eligible' => true, 'reason' => null],
                'blockers' => $blockers,
            ];
        }

        $missing = (array) ($completion['missing_required_owners'] ?? []);
        $children = (array) ($completion['children'] ?? []);
        foreach ($requested as $child) {
            foreach ($missing as $owner) {
                if (is_array($owner) && strtolower(trim((string) ($owner['owner_type'] ?? ''))) === $child) {
                    return [
                        'lifecycle_state' => $lifecycleState,
                        'retry' => ['eligible' => true, 'reason' => null],
                        'blockers' => $blockers,
                    ];
                }
            }
            foreach ($children as $owner) {
                if (!is_array($owner) || strtolower(trim((string) ($owner['owner_type'] ?? ''))) !== $child) continue;
                if (($owner['complete'] ?? false) !== true || strtoupper(trim((string) ($owner['status'] ?? ''))) !== 'COMPLETE') {
                    return [
                        'lifecycle_state' => $lifecycleState,
                        'retry' => ['eligible' => true, 'reason' => null],
                        'blockers' => $blockers,
                    ];
                }
            }
        }

        return ['lifecycle_state' => $lifecycleState, 'retry' => $retry, 'blockers' => $blockers];
    }

    private static function hasActiveExecution(CaptureRecord $capture): bool
    {
        $now = time();
        foreach ($capture->phaseReceipts as $receipt) {
            if (!is_array($receipt)) continue;
            $latest = CapturePhaseReceiptReducer::latest($receipt);
            if (strtoupper(trim((string) ($latest['status'] ?? ''))) !== 'STARTED') continue;
            if (trim((string) ($latest['completed_at'] ?? '')) !== '') continue;
            $startedAt = strtotime((string) ($latest['started_at'] ?? ''));
            if ($startedAt !== false && $startedAt <= $now && ($now - $startedAt) <= self::ACTIVE_EXECUTION_TTL_SECONDS) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $diagnostics @param array<string,mixed> $phaseReceipts @return list<string> */
    public static function currentBlockers(array $diagnostics, array $phaseReceipts, ?CaptureRecord $capture = null, array $input = []): array
    {
        $currentByPhase = CapturePhaseReceiptReducer::currentFailureCodesByPhase($phaseReceipts);
        $reevaluableArticleReview = self::isReevaluatableArticleReview($diagnostics, $phaseReceipts, $capture, $input);
        $current = [];
        foreach ($currentByPhase as $phase => $codes) {
            if ($reevaluableArticleReview && strtoupper(trim($phase)) === 'ARTICLE_PRE_CREATE_REVIEW') continue;
            $current = array_merge($current, $codes);
        }
        $current = array_values(array_unique($current));
        $superseded = CapturePhaseReceiptReducer::supersededFailureCodes($phaseReceipts);
        $blockers = $current;

        $completion = is_array($diagnostics['completion'] ?? null) ? $diagnostics['completion'] : [];
        $candidates = array_merge(
            [(string) (($diagnostics['failure']['code'] ?? '') ?: '')],
            array_map('strval', (array) ($completion['blockers'] ?? [])),
        );
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') continue;
            if ($reevaluableArticleReview && !in_array(strtoupper($candidate), ['OWNER_REVIEW_REQUIRED', 'SYSTEM_BLOCKED'], true)) continue;
            if (in_array($candidate, $superseded, true) && !in_array($candidate, $current, true)) continue;
            $blockers[] = $candidate;
        }

        return array_values(array_unique($blockers));
    }

    /** @param array<string,mixed> $diagnostics @param array<string,mixed> $phaseReceipts @return array<string,mixed> */
    public static function reconcileDiagnostics(array $diagnostics, array $phaseReceipts, ?CaptureRecord $capture = null, array $input = []): array
    {
        $current = self::currentBlockers($diagnostics, $phaseReceipts, $capture, $input);
        $superseded = CapturePhaseReceiptReducer::supersededFailureCodes($phaseReceipts);
        $history = is_array($diagnostics['failure_history'] ?? null) ? array_values(array_filter($diagnostics['failure_history'], 'is_array')) : [];
        $historyCodes = array_values(array_filter(array_map(
            static fn (array $item): string => trim((string) ($item['code'] ?? '')),
            $history,
        ), static fn (string $code): bool => $code !== ''));
        $completion = is_array($diagnostics['completion'] ?? null) ? $diagnostics['completion'] : [];
        $topLevelCodes = array_merge(
            [(string) (($diagnostics['failure']['code'] ?? '') ?: '')],
            array_map('strval', (array) ($completion['blockers'] ?? [])),
        );
        foreach ($topLevelCodes as $code) {
            $code = trim($code);
            if ($code === '' || !in_array($code, $superseded, true) || in_array($code, $current, true) || in_array($code, $historyCodes, true)) continue;
            $history[] = ['code' => $code, 'reason' => 'SUPERSEDED_BY_LATEST_PHASE_OUTCOME'];
            $historyCodes[] = $code;
        }

        if ($current === [] && self::isReevaluatableArticleReview($diagnostics, $phaseReceipts, $capture, $input)) {
            $failureCode = trim((string) (($diagnostics['failure']['code'] ?? '') ?: ''));
            if ($failureCode !== '' && !in_array(strtoupper($failureCode), ['OWNER_REVIEW_REQUIRED', 'SYSTEM_BLOCKED'], true) && !in_array($failureCode, $historyCodes, true)) {
                $history[] = ['code' => $failureCode, 'reason' => 'ARTICLE_PRE_CREATE_REVIEW_REEVALUATABLE'];
                $historyCodes[] = $failureCode;
                unset($diagnostics['failure']);
            }
        }

        if ($current !== []) {
            $completion['blockers'] = $current;
            $diagnostics['completion'] = $completion;
            if (is_array($diagnostics['failure'] ?? null)) {
                $failure = $diagnostics['failure'];
                $failure['code'] = $current[0];
                $diagnostics['failure'] = $failure;
            }
        } else {
            $completion['blockers'] = [];
            $diagnostics['completion'] = $completion;
            $failureCode = trim((string) (($diagnostics['failure']['code'] ?? '') ?: ''));
            if ($failureCode !== '' && in_array($failureCode, $superseded, true)) unset($diagnostics['failure']);
        }
        if ($history !== []) $diagnostics['failure_history'] = $history;

        return $diagnostics;
    }

    /** @param array<string,mixed> $diagnostics */
    private static function isReevaluatableArticleReview(array $diagnostics, array $phaseReceipts = [], ?CaptureRecord $capture = null, array $input = []): bool
    {
        return ArticleReviewFreshness::isReevaluatable($capture, $diagnostics, $input);
    }

    /** @param list<string> $blockers */
    private static function isReevaluatableReview(CaptureRecord $capture, array $input, array $blockers): bool
    {
        if (self::isReevaluatableArticleReview($capture->diagnostics, $capture->phaseReceipts, $capture, $input)) return true;
        if (CapturePhaseReceiptReducer::hasStaleInheritedArticleReview($capture->phaseReceipts)) return true;
        if (!in_array($capture->status, ['REVIEW_REQUIRED', 'IN_PROGRESS'], true)) return false;
        if (self::isHardBlockedReview($capture) || in_array('CATEGORY_UNRESOLVED', $blockers, true) || self::hasCurrentArticleOverlapReview($capture)) return false;
        $knownReevaluatableBlockers = array_intersect($blockers, ['KNOWLEDGE_SEMANTIC_HANDOFF_REQUIRED', 'KNOWLEDGE_SCOPE_INCOMPATIBLE', 'KNOWLEDGE_SCOPE_UNRESOLVED', 'KNOWLEDGE_FACET_UNSUPPORTED', 'KNOWLEDGE_SUBJECT_TYPE_UNSUPPORTED', 'REQUIRED_OWNER_READBACK_UNVERIFIED']);
        if ($knownReevaluatableBlockers === [] && $capture->status !== 'REVIEW_REQUIRED') return false;
        $completion = is_array($capture->diagnostics['completion'] ?? null) ? $capture->diagnostics['completion'] : [];
        if (!in_array(strtoupper(trim((string) ($completion['status'] ?? ''))), ['PARTIAL', 'REVIEW_REQUIRED'], true)) return false;
        $persisted = trim((string) ($capture->diagnostics['decision_dependency_fingerprint'] ?? $capture->context['decision_dependency_fingerprint'] ?? ''));
        $current = CaptureDecisionDependencyFingerprint::current($capture, $input);
        return $persisted === '' || !hash_equals($persisted, $current);
    }

    public static function effectiveCapture(CaptureRecord $capture): CaptureRecord
    {
        $diagnostics = self::reconcileDiagnostics($capture->diagnostics, $capture->phaseReceipts, $capture);
        if ($diagnostics === $capture->diagnostics) return $capture;
        return new CaptureRecord(
            $capture->captureId,
            $capture->idempotencyKey,
            $capture->requestFingerprint,
            $capture->stage,
            $capture->status,
            $capture->articleId,
            $capture->articleStateToken,
            $capture->assets,
            $capture->context,
            $diagnostics,
            $capture->phaseReceipts,
            $capture->revision,
            $capture->createdAt,
            $capture->updatedAt,
        );
    }

    /**
     * Single retry policy consumed by both the read model and retry executor.
     * The read model may report the decision, but never grants execution.
     *
     * @return array{eligible:bool,reason:?string}
     */
    public static function retryEligibility(CaptureRecord $capture, array $input = []): array
    {
        return self::currentDecision($capture, $input)['retry'];
    }

    private static function isHardBlockedReview(CaptureRecord $capture): bool
    {
        $failure = is_array($capture->diagnostics['failure'] ?? null) ? $capture->diagnostics['failure'] : [];
        $preparation = is_array($capture->diagnostics['content_preparation'] ?? null) ? $capture->diagnostics['content_preparation'] : [];
        if (strtoupper(trim((string) ($failure['classification'] ?? ''))) === 'HARD_BLOCK') return true;
        if (strtoupper(trim((string) ($preparation['quality_decision'] ?? ''))) === 'HARD_BLOCK') return true;
        return in_array('HARD_BLOCK', array_map('strval', (array) ($preparation['blockers'] ?? [])), true);
    }

    private static function hasCurrentArticleOverlapReview(CaptureRecord $capture): bool
    {
        $latest = CapturePhaseReceiptReducer::latest((array) ($capture->phaseReceipts['ARTICLE_PRE_CREATE_REVIEW'] ?? []));
        if (strtoupper(trim((string) ($latest['status'] ?? ''))) !== 'REVIEW_REQUIRED') return false;
        $code = strtoupper(trim((string) ($latest['failure_code'] ?? '')));
        if (!in_array($code, ['SUBSTANTIAL_OVERLAP', 'ARTICLE_SUBSTANTIAL_OVERLAP', 'EXISTING_ARTICLE_OVERLAP'], true)) return false;
        $resolution = is_array($capture->diagnostics['article_resolution'] ?? null) ? $capture->diagnostics['article_resolution'] : [];
        $research = is_array($resolution['research'] ?? null) ? $resolution['research'] : [];
        $overlap = is_array($research['overlap_analysis'] ?? null) ? $research['overlap_analysis'] : (is_array($resolution['overlap'] ?? null) ? $resolution['overlap'] : []);
        return strtoupper(trim((string) ($overlap['classification'] ?? ''))) === 'SUBSTANTIAL_OVERLAP'
            && !ArticleReviewFreshness::isReevaluatable($capture, $capture->diagnostics);
    }

    public static function supportsCanonicalVideoCompletionRetry(CaptureRecord $capture): bool
    {
        $intent = is_array($capture->context['content_intent'] ?? null) ? $capture->context['content_intent'] : [];
        if (strtoupper(trim((string) ($intent['intent'] ?? ''))) !== 'VIDEO') return false;
        $semantic = is_array($capture->diagnostics['semantic_write_back'] ?? null)
            ? $capture->diagnostics['semantic_write_back']
            : [];
        if (!in_array(strtoupper(trim((string) ($semantic['status'] ?? ''))), ['APPLIED', 'IDEMPOTENT', 'REUSED', 'REUSED_VERIFIED', 'ALREADY_APPLIED'], true)) return false;
        $hasReadback = is_array($semantic['canonical_readback'] ?? null)
            && trim((string) ($semantic['canonical_readback']['canonical_id'] ?? '')) !== '';
        foreach ((array) ($semantic['writes'] ?? []) as $write) {
            if (!is_array($write)) continue;
            $readback = is_array($write['canonical_readback'] ?? null) ? $write['canonical_readback'] : [];
            if (trim((string) ($readback['canonical_id'] ?? $write['canonical_id'] ?? '')) !== '') {
                $hasReadback = true;
                break;
            }
        }
        if (!$hasReadback) return false;
        foreach ($capture->assets as $asset) {
            if (is_array($asset) && strtolower(trim((string) ($asset['kind'] ?? ''))) === 'video') return true;
        }
        return false;
    }

    public static function failureCode(CaptureRecord $capture): ?string
    {
        return self::currentBlockers($capture->diagnostics, $capture->phaseReceipts)[0] ?? null;
    }
}
