<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Application\Article\ArticleReviewFreshness;
use NHK\Core\Domain\Capture\CaptureRecord;

/** Derives current retry truth from latest phase outcomes and completion evidence. */
final class CaptureCurrentOutcomeReducer
{
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
        if ($capture->status === 'FAILED_RETRYABLE') return ['eligible' => true, 'reason' => null];
        if (in_array($capture->stage, ['READY_FOR_PUBLICATION', 'PUBLISHED'], true)) return ['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'];
        if (in_array($capture->status, ['APPLIED', 'REVIEW_REQUIRED'], true)
            && is_array($input['subject_reconciliation'] ?? null)
            && ($input['subject_reconciliation']['confirmed'] ?? false) === true) {
            return ['eligible' => true, 'reason' => null];
        }

        $completion = is_array($capture->diagnostics['completion'] ?? null) ? $capture->diagnostics['completion'] : [];
        $completionBlockers = self::currentBlockers($capture->diagnostics, $capture->phaseReceipts, $capture, $input);
        if (in_array('CATEGORY_UNRESOLVED', $completionBlockers, true) || self::failureCode($capture) === 'CATEGORY_UNRESOLVED') {
            return ['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'];
        }
        if ($capture->status === 'REVIEW_REQUIRED'
            && self::hasCurrentArticleOverlapReview($capture)
        ) {
            return ['eligible' => false, 'reason' => 'CURRENT_REVIEW_REQUIRED'];
        }
        if ($capture->status === 'REVIEW_REQUIRED'
            && !self::isHardBlockedReview($capture)
            && (CapturePhaseReceiptReducer::hasStaleInheritedArticleReview($capture->phaseReceipts)
                || ArticleReviewFreshness::isReevaluatable($capture, $capture->diagnostics))) {
            return ['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'];
        }
        if (!in_array(strtoupper(trim((string) ($completion['status'] ?? ''))), ['PARTIAL', 'REVIEW_REQUIRED'], true)) {
            return ['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'];
        }
        if ($capture->status === 'REVIEW_REQUIRED') {
            if (self::isHardBlockedReview($capture)) return ['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'];
            $persisted = trim((string) ($capture->diagnostics['decision_dependency_fingerprint'] ?? $capture->context['decision_dependency_fingerprint'] ?? ''));
            $current = CaptureDecisionDependencyFingerprint::current($capture, $input);
            if ($persisted === '' || !hash_equals($persisted, $current)) {
                return ['eligible' => true, 'reason' => 'STALE_REVIEW_REEVALUATABLE'];
            }
        }
        $hintPacket = is_array($capture->diagnostics['resume_hints'] ?? null)
            ? $capture->diagnostics['resume_hints']
            : (is_array($completion['resume_hints'] ?? null) ? $completion['resume_hints'] : []);
        $hints = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($hintPacket['resume_children'] ?? [])))));
        $requested = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($input['resume_children'] ?? [])))));
        if ($requested === []) $requested = $hints;
        // A historical Video Capture may have lost its original resume hint
        // while the canonical Video and its governed read-back remain valid.
        // In that state the only safe continuation is the bounded completion
        // check; do not force the operator back through semantic Governance.
        if ($hints === [] && self::supportsCanonicalVideoCompletionRetry($capture)) $hints = ['video'];
        if ($requested === [] && self::supportsCanonicalVideoCompletionRetry($capture)) $requested = ['video'];
        if ($hints === [] || $requested === [] || array_diff($requested, $hints) !== []) return ['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'];
        if (self::supportsCanonicalVideoCompletionRetry($capture) && $requested === ['video']) return ['eligible' => true, 'reason' => null];

        $missing = (array) ($completion['missing_required_owners'] ?? []);
        $children = (array) ($completion['children'] ?? []);
        foreach ($requested as $child) {
            foreach ($missing as $owner) {
                if (is_array($owner) && strtolower(trim((string) ($owner['owner_type'] ?? ''))) === $child) return ['eligible' => true, 'reason' => null];
            }
            foreach ($children as $owner) {
                if (!is_array($owner) || strtolower(trim((string) ($owner['owner_type'] ?? ''))) !== $child) continue;
                if (($owner['complete'] ?? false) !== true || strtoupper(trim((string) ($owner['status'] ?? ''))) !== 'COMPLETE') return ['eligible' => true, 'reason' => null];
            }
        }
        return ['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'];
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
