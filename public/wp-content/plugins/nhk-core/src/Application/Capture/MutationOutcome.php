<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

final class MutationOutcome
{
    private function __construct(
        private string $status,
        private string $reason = '',
        private array $payload = [],
        private array $identity = [],
    ) {
    }

    public static function successWithReadback(array $payload): self
    {
        if (trim((string) ($payload['canonical_id'] ?? '')) === '' || (int) ($payload['revision'] ?? 0) < 1) {
            throw new \InvalidArgumentException('Canonical read-back identity and revision are required.');
        }

        return new self('SUCCESS_WITH_READBACK', '', $payload, [
            'canonical_id' => (string) $payload['canonical_id'],
        ]);
    }

    public static function failedConfirmed(string $code, array $diagnostics = []): self
    {
        $code = trim($code);
        if ($code === '') throw new \InvalidArgumentException('Confirmed failure code is required.');
        return new self('FAILED_CONFIRMED', $code, $diagnostics);
    }

    public static function unknown(string $reason, array $identity = []): self
    {
        $reason = trim($reason);
        if ($reason === '') throw new \InvalidArgumentException('Unknown outcome reason is required.');
        return new self('OUTCOME_UNKNOWN', $reason, [], $identity);
    }

    public function status(): string { return $this->status; }
    public function reason(): string { return $this->reason; }
    public function payload(): array { return $this->payload; }
    public function identity(): array { return $this->identity; }
}
