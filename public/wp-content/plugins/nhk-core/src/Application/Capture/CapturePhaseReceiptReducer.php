<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/**
 * Reduces append-only phase attempts into a backward-compatible latest receipt.
 * Historical attempts are never removed or rewritten.
 */
final class CapturePhaseReceiptReducer
{
    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    public static function latest(array $receipt): array
    {
        $attempts = is_array($receipt['attempts'] ?? null)
            ? array_values(array_filter($receipt['attempts'], 'is_array'))
            : [];
        if ($attempts !== []) {
            $latest = is_array($receipt['latest'] ?? null)
                ? $receipt['latest']
                : $attempts[count($attempts) - 1];
            $latest['current_outcome'] = 'CURRENT';
            return $latest;
        }

        if ($receipt === []) return [];
        $latest = self::legacyAttempt($receipt, 1);
        $latest['current_outcome'] = 'CURRENT';
        return $latest;
    }

    /** @param array<string,mixed> $receipts @return list<string> */
    public static function currentFailureCodes(array $receipts): array
    {
        $codes = [];
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) continue;
            $latest = self::latest($receipt);
            $code = trim((string) ($latest['failure_code'] ?? ''));
            if ($code === '' || !self::isFailureOutcome($latest)) continue;
            $codes[] = $code;
        }
        return array_values(array_unique($codes));
    }

    /** @param array<string,mixed> $receipts @return list<string> */
    public static function supersededFailureCodes(array $receipts): array
    {
        $codes = [];
        foreach ($receipts as $receipt) {
            if (!is_array($receipt)) continue;
            $attempts = is_array($receipt['attempts'] ?? null)
                ? array_values(array_filter($receipt['attempts'], 'is_array'))
                : [];
            if ($attempts !== []) {
                array_pop($attempts);
                foreach ($attempts as $attempt) {
                    $code = trim((string) ($attempt['failure_code'] ?? ''));
                    if ($code !== '') $codes[] = $code;
                }
            }
            $latest = self::latest($receipt);
            foreach ((array) ($latest['superseded_failure_codes'] ?? []) as $code) {
                $code = trim((string) $code);
                if ($code !== '') $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }

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

    /** @param array<string,mixed> $attempt */
    private static function isFailureOutcome(array $attempt): bool
    {
        $status = strtoupper(trim((string) ($attempt['status'] ?? '')));
        if (in_array($status, ['FAILED', 'BLOCKED', 'REVIEW_REQUIRED'], true)) return true;
        $result = strtoupper(trim((string) ($attempt['result'] ?? '')));
        return str_contains($result, 'FAILED')
            || str_contains($result, 'BLOCKED')
            || str_contains($result, 'REVIEW_REQUIRED');
    }
}
