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
        $hintPacket = is_array($capture->diagnostics['resume_hints'] ?? null)
            ? $capture->diagnostics['resume_hints']
            : (is_array($completion['resume_hints'] ?? null) ? $completion['resume_hints'] : []);
        $hints = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($hintPacket['resume_children'] ?? [])))));
        $requested = array_values(array_unique(array_map('strtolower', array_map('strval', (array) ($input['resume_children'] ?? [])))));
        if ($requested === []) $requested = $hints;
        if ($hints === [] || $requested === [] || array_diff($requested, $hints) !== []) return ['eligible' => false, 'reason' => 'CAPTURE_RETRY_NOT_ALLOWED'];

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
        $completion = is_array($capture->diagnostics['completion'] ?? null) ? $capture->diagnostics['completion'] : [];
        $blockers = array_values(array_filter(array_map('strval', (array) ($completion['blockers'] ?? [])), static fn (string $code): bool => trim($code) !== ''));
        return $blockers[0] ?? null;
    }
}
