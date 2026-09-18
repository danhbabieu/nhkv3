<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/**
 * Reduces append-only phase attempts into a backward-compatible latest receipt.
 * Historical attempts are never removed or rewritten.
 */
final class CapturePhaseReceiptReducer
{
    /** @param array<string,mixed> $receipts @param array<string,mixed> $attempt @return array<string,mixed> */
    public static function append(array $receipts, string $phase, array $attempt): array
    {
        $phase = trim($phase);
        if ($phase === '') return $receipts;
        $prior = is_array($receipts[$phase] ?? null) ? $receipts[$phase] : [];
        $attempts = is_array($prior['attempts'] ?? null) ? array_values(array_filter($prior['attempts'], 'is_array')) : [];
        if ($attempts === [] && $prior !== []) $attempts[] = self::legacyAttempt($prior, 1);
        $number = count($attempts) + 1;
        $current = $attempt;
        $current['attempt_no'] = $number;
        $current['attempt_id'] = trim((string) ($current['attempt_id'] ?? ($phase . ':' . $number)));
        $current['superseded_failure_codes'] = array_values(array_unique(array_filter(array_map(
            static fn (array $item): string => trim((string) ($item['failure_code'] ?? '')),
            $attempts,
        ), static fn (string $code): bool => $code !== '')));
        $attempts[] = $current;
        $latest = $current;
        $latest['current_outcome'] = 'CURRENT';
        return array_replace($receipts, [$phase => array_replace($current, [
            'attempts' => $attempts,
            'latest' => $latest,
            'current_outcome' => 'CURRENT',
        ])]);
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private static function legacyAttempt(array $receipt, int $number): array
    {
        $attempt = $receipt;
        $attempt['attempt_no'] = $number;
        $attempt['attempt_id'] = 'legacy:' . $number;
        $attempt['superseded_failure_codes'] = [];
        return $attempt;
    }
}
