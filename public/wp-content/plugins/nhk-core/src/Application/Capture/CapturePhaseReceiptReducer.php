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
        $latest = is_array($receipt['latest'] ?? null)
            ? self::legacyAttempt($receipt['latest'], 1)
            : self::legacyAttempt($receipt, 1);
        $latest['current_outcome'] = 'CURRENT';
        return $latest;
    }

    /** @param array<string,mixed> $receipts @return list<string> */
    public static function currentFailureCodes(array $receipts): array
    {
        $codes = [];
        foreach (self::currentFailureCodesByPhase($receipts) as $phaseCodes) {
            foreach ($phaseCodes as $code) $codes[] = $code;
        }
        return array_values(array_unique($codes));
    }

    /** @param array<string,mixed> $receipts @return array<string,list<string>> */
    public static function currentFailureCodesByPhase(array $receipts): array
    {
        $codes = [];
        foreach (array_values($receipts) as $position => $receipt) {
            if (!is_array($receipt)) continue;
            $latest = self::latest($receipt);
            $code = trim((string) ($latest['failure_code'] ?? ''));
            if ($code === '' || !self::isFailureOutcome($latest)) continue;
            $phase = (string) array_keys($receipts)[$position];
            if (self::legacyFailureWasSuperseded($receipts, $position, $latest, $phase)
                || self::legacyReviewInheritedFailureWasSuperseded($receipts, $position, $latest, $phase)) continue;
            $codes[$phase][] = $code;
        }
        foreach ($codes as $phase => $phaseCodes) $codes[$phase] = array_values(array_unique($phaseCodes));
        return $codes;
    }

    /** @param array<string,mixed> $receipts @return list<string> */
    public static function supersededFailureCodes(array $receipts): array
    {
        $codes = [];
        foreach (array_values($receipts) as $position => $receipt) {
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
            $phase = (string) array_keys($receipts)[$position];
            if (self::legacyFailureWasSuperseded($receipts, $position, $latest, $phase)
                || self::legacyReviewInheritedFailureWasSuperseded($receipts, $position, $latest, $phase)) {
                $code = trim((string) ($latest['failure_code'] ?? ''));
                if ($code !== '') $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }

    /** @param array<string,mixed> $receipts */
    public static function hasStaleInheritedArticleReview(array $receipts): bool
    {
        foreach (array_values($receipts) as $position => $receipt) {
            if (!is_array($receipt)) continue;
            $phase = (string) array_keys($receipts)[$position];
            if (self::legacyReviewInheritedFailureWasSuperseded($receipts, $position, self::latest($receipt), $phase)) return true;
        }
        return false;
    }

    /**
     * A retryable failure phase is historical once a later phase has started
     * and produced an effective successor. Its attempts remain audit-visible.
     *
     * @param array<string,mixed> $receipts
     * @param array<string,mixed> $latest
     */
    private static function legacyFailureWasSuperseded(array $receipts, int $position, array $latest, string $phase): bool
    {
        $receipt = array_values($receipts)[$position] ?? null;
        if (!is_array($receipt)) return false;
        $code = strtoupper(trim((string) ($latest['failure_code'] ?? '')));
        if ($code === '' || in_array($code, ['OWNER_REVIEW_REQUIRED', 'SYSTEM_BLOCKED'], true) || str_contains($code, 'GOVERNANCE')) return false;

        $status = strtoupper(trim((string) ($latest['status'] ?? '')));
        $result = strtoupper(trim((string) ($latest['result'] ?? '')));
        $retryable = $result === 'FAILED_RETRYABLE' || strtoupper(trim((string) ($latest['classification'] ?? ''))) === 'FAILED_RETRYABLE';
        $staleArticleReview = strtoupper(trim($phase)) === 'ARTICLE_PRE_CREATE_REVIEW'
            && $status === 'REVIEW_REQUIRED'
            && $result === 'REVIEW_REQUIRED';
        if (!$retryable && !$staleArticleReview) return false;

        $later = array_values($receipts);
        foreach (array_slice($later, $position + 1) as $successor) {
            if (!is_array($successor) || self::latest($successor) === []) continue;
            return true;
        }
        return false;
    }

    /**
     * A legacy Article pre-create review could copy the previous retryable
     * failure into its own current row. The row has no attempts list, so the
     * only safe recovery signal is the ordered persisted history: a matching
     * retryable failure followed by at least one completed phase.
     *
     * @param array<string,mixed> $receipts
     * @param array<string,mixed> $latest
     */
    private static function legacyReviewInheritedFailureWasSuperseded(array $receipts, int $position, array $latest, string $phase): bool
    {
        if (strtoupper(trim($phase)) !== 'ARTICLE_PRE_CREATE_REVIEW') return false;
        if (strtoupper(trim((string) ($latest['status'] ?? ''))) !== 'REVIEW_REQUIRED'
            || strtoupper(trim((string) ($latest['result'] ?? ''))) !== 'REVIEW_REQUIRED') return false;

        $code = strtoupper(trim((string) ($latest['failure_code'] ?? '')));
        if ($code === '') return false;
        $seenRetryable = false;
        $sawCompletedAfterFailure = false;
        foreach (array_values($receipts) as $index => $receipt) {
            if ($index >= $position || !is_array($receipt)) continue;
            $prior = self::latest($receipt);
            $priorCode = strtoupper(trim((string) ($prior['failure_code'] ?? '')));
            $priorResult = strtoupper(trim((string) ($prior['result'] ?? '')));
            $priorStatus = strtoupper(trim((string) ($prior['status'] ?? '')));
            if ($priorCode === $code && ($priorResult === 'FAILED_RETRYABLE' || strtoupper(trim((string) ($prior['classification'] ?? ''))) === 'FAILED_RETRYABLE')) {
                $seenRetryable = true;
                continue;
            }
            if ($seenRetryable && $priorStatus === 'COMPLETED') $sawCompletedAfterFailure = true;
        }
        return $seenRetryable && $sawCompletedAfterFailure;
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
