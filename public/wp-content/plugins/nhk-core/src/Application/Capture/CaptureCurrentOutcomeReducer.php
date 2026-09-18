<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

use NHK\Core\Domain\Capture\CaptureRecord;

/** Derives current retry truth from latest phase outcomes and completion evidence. */
final class CaptureCurrentOutcomeReducer
{
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
