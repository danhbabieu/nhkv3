<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

final class MutationOutcomeClassifier
{
    public function classify(mixed $response, array $dispatchContext = []): MutationOutcome
    {
        $identity = $this->identity($dispatchContext);
        if ($response instanceof MutationOutcome) return $response;
        if (is_object($response) && get_class($response) === stdClass::class) {
            return MutationOutcome::unknown('EMPTY_RESPONSE', $identity);
        }
        if (!is_array($response)) return MutationOutcome::unknown('MALFORMED_RESPONSE', $identity);

        $status = strtoupper(trim((string) ($response['outcome'] ?? $response['status'] ?? '')));
        if (in_array($status, ['OUTCOME_UNKNOWN', 'UNKNOWN'], true)) {
            return MutationOutcome::unknown((string) ($response['reason'] ?? 'UNKNOWN_RESPONSE'), $identity);
        }
        if (in_array($status, ['FAILED_CONFIRMED', 'FAILED', 'BLOCKED'], true)) {
            return MutationOutcome::failedConfirmed((string) ($response['failure_code'] ?? $response['reason'] ?? 'MUTATION_FAILED'), $response);
        }
        if (trim((string) ($response['canonical_id'] ?? '')) !== '' && (int) ($response['revision'] ?? 0) > 0) {
            return MutationOutcome::successWithReadback($response);
        }
        return MutationOutcome::unknown('MALFORMED_RESPONSE', $identity);
    }

    private function identity(array $context): array
    {
        $identity = [];
        foreach (['idempotency_key', 'capture_id', 'request_fingerprint', 'external_video_id'] as $key) {
            if (trim((string) ($context[$key] ?? '')) !== '') $identity[$key] = (string) $context[$key];
        }
        return $identity;
    }
}
