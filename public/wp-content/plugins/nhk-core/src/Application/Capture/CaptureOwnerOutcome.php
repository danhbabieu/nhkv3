<?php
declare(strict_types=1);

namespace NHK\Core\Application\Capture;

/**
 * Orchestration-only owner result. Domain lifecycles remain domain-native;
 * this value object only normalizes what Capture needs to resume safely.
 */
final readonly class CaptureOwnerOutcome
{
    public const STATUSES = [
        'NOT_APPLICABLE', 'PLANNED', 'REVIEW_REQUIRED', 'BLOCKED', 'READY',
        'APPLIED', 'READ_BACK_VERIFIED', 'FAILED_RETRYABLE', 'FAILED_FINAL',
    ];

    private function __construct(
        public string $owner,
        public string $status,
        public string $domainStatus = '',
        public string $planFingerprint = '',
        public int $expectedRevision = 0,
        public array $dependencyRevisions = [],
        public array $canonicalReadback = [],
        public array $diagnostics = [],
        public array $receipt = [],
    ) {
    }

    public static function fromDomainResult(string $owner, array $result): self
    {
        $canonicalReadback = is_array($result['canonical_readback'] ?? null)
            ? $result['canonical_readback']
            : [];
        if ($canonicalReadback === [] && trim((string) ($result['canonical_id'] ?? '')) !== '') {
            $canonicalReadback = ['canonical_id' => (string) $result['canonical_id']];
            if (isset($result['revision'])) $canonicalReadback['revision'] = (int) $result['revision'];
        }
        $status = self::normalizeStatus($result);
        return new self(
            self::normalizeOwner($owner),
            $status,
            strtoupper(trim((string) ($result['status'] ?? $result['outcome'] ?? ''))),
            trim((string) ($result['plan_fingerprint'] ?? '')),
            max(0, (int) ($result['expected_revision'] ?? $result['revision'] ?? 0)),
            self::revisionMap($result['dependency_revisions'] ?? $result['dependencies'] ?? []),
            $canonicalReadback,
            is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : [],
            is_array($result['receipt'] ?? null) ? $result['receipt'] : [],
        );
    }

    public static function fromArray(array $value): self
    {
        return new self(
            self::normalizeOwner((string) ($value['owner'] ?? $value['owner_type'] ?? '')),
            self::normalizeStatus($value),
            strtoupper(trim((string) ($value['domain_status'] ?? ''))),
            trim((string) ($value['plan_fingerprint'] ?? '')),
            max(0, (int) ($value['expected_revision'] ?? 0)),
            self::revisionMap($value['dependency_revisions'] ?? []),
            self::readback($value),
            is_array($value['diagnostics'] ?? null) ? $value['diagnostics'] : [],
            is_array($value['receipt'] ?? null) ? $value['receipt'] : [],
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'READ_BACK_VERIFIED';
    }

    public function toArray(): array
    {
        return [
            'owner' => $this->owner,
            'status' => $this->status,
            'domain_status' => $this->domainStatus,
            'plan_fingerprint' => $this->planFingerprint,
            'expected_revision' => $this->expectedRevision,
            'dependency_revisions' => $this->dependencyRevisions,
            'canonical_readback' => $this->canonicalReadback,
            'diagnostics' => $this->diagnostics,
            'receipt' => $this->receipt,
        ];
    }

    private static function normalizeOwner(string $owner): string
    {
        $owner = strtolower(trim($owner));
        return $owner === 'dictionary' ? 'lexical' : ($owner === 'graph' ? 'relations' : $owner);
    }

    private static function normalizeStatus(array $result): string
    {
        $status = strtoupper(trim((string) ($result['orchestration_status'] ?? '')));
        if ($status === '') {
            $domain = strtoupper(trim((string) ($result['status'] ?? $result['outcome'] ?? '')));
            $status = match ($domain) {
                'REUSED', 'IDEMPOTENT', 'ALREADY_APPLIED', 'REUSED_VERIFIED', 'VERIFIED', 'READ_BACK_VERIFIED', 'SUCCESS_WITH_READBACK' => 'READ_BACK_VERIFIED',
                'APPLIED', 'COMPLETED' => 'APPLIED',
                'READY', 'ELIGIBLE' => 'READY',
                'PLANNED' => 'PLANNED',
                'REVIEW_REQUIRED', 'REVIEW' => 'REVIEW_REQUIRED',
                'BLOCKED', 'SYSTEM_BLOCKED' => 'BLOCKED',
                'FAILED_RETRYABLE', 'RETRYABLE' => 'FAILED_RETRYABLE',
                'FAILED_FINAL', 'FAILED_CONFIRMED' => 'FAILED_FINAL',
                'SKIPPED', 'NOT_REQUESTED', 'NOT_APPLICABLE' => 'NOT_APPLICABLE',
                default => 'FAILED_RETRYABLE',
            };
        }
        if (!in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException('Capture owner outcome status is invalid.');
        return $status;
    }

    private static function revisionMap(mixed $value): array
    {
        $result = [];
        foreach ((array) $value as $key => $revision) {
            if (is_array($revision)) $revision = $revision['revision'] ?? $revision['expected_revision'] ?? null;
            if ($revision === null || !is_scalar($revision)) continue;
            $result[(string) $key] = (int) $revision;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private static function readback(array $value): array
    {
        $readback = is_array($value['canonical_readback'] ?? null) ? $value['canonical_readback'] : [];
        if (!array_key_exists('revision', $readback) && isset($value['readback_revision'])) $readback['revision'] = (int) $value['readback_revision'];
        return $readback;
    }
}
