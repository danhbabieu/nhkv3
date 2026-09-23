<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Capture\CaptureRecord;

/** Derives current retry truth from latest phase outcomes and completion evidence. */
final class CaptureCurrentOutcomeReducer
{
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

        $completion = is_array($capture->diagnostics['completion'] ?? null) ? $capture->diagnostics['completion'] : [];
        if (!in_array(strtoupper(trim((string) ($completion['status'] ?? ''))), ['PARTIAL', 'REVIEW_REQUIRED'], true)) {
            return ['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'];
        }
        $completionBlockers = array_values(array_map('strval', (array) ($completion['blockers'] ?? [])));
        if (in_array('CATEGORY_UNRESOLVED', $completionBlockers, true) || self::failureCode($capture) === 'CATEGORY_UNRESOLVED') {
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
        foreach ($capture->phaseReceipts as $receipt) {
            if (!is_array($receipt)) continue;
            $latest = is_array($receipt['latest'] ?? null) ? $receipt['latest'] : $receipt;
            $status = strtoupper(trim((string) ($latest['status'] ?? '')));
            $result = strtoupper(trim((string) ($latest['result'] ?? '')));
            if (in_array($status, ['FAILED', 'BLOCKED', 'REVIEW_REQUIRED'], true) || str_contains($result, 'FAILED')) {
                $code = trim((string) ($latest['failure_code'] ?? ''));
                if ($code !== '') return $code;
            }
        }
        // Append-only receipts retain historical attempts, but only the
        // latest attempt in each phase can represent the current outcome.
        // A completed current attempt therefore clears an older failure code.
        $completion = is_array($capture->diagnostics['completion'] ?? null) ? $capture->diagnostics['completion'] : [];
        $blockers = array_values(array_filter(array_map('strval', (array) ($completion['blockers'] ?? [])), static fn (string $code): bool => trim($code) !== ''));
        return $blockers[0] ?? null;
    }
}
