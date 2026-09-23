<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Transient/testable usage memory; it is not canonical truth or persistence. */
final class EditorialUsageMemory
{
    /** @var array<string,array<string,mixed>> */
    private array $records = [];

    /** @param array<string,mixed> $usage @return array<string,mixed> */
    public function record(string $idempotencyKey, array $usage): array
    {
        $key = trim($idempotencyKey);
        if (($usage['state'] ?? '') !== 'PUBLISHED' || $key === '') return ['status' => 'IGNORED', 'idempotency_key' => $key];
        if (isset($this->records[$key])) return ['status' => 'REPLAY', 'idempotency_key' => $key, 'usage' => $this->records[$key]];
        $this->records[$key] = [
            'claim_id' => (string) ($usage['claim_id'] ?? ''),
            'unit_id' => (string) ($usage['unit_id'] ?? ''),
            'subject_id' => (string) ($usage['subject_id'] ?? ''),
            'surface' => (string) ($usage['surface'] ?? ''),
            'editorial_role' => (string) ($usage['editorial_role'] ?? ''),
            'used_at' => (string) ($usage['used_at'] ?? 'now'),
        ];
        return ['status' => 'RECORDED', 'idempotency_key' => $key, 'usage' => $this->records[$key]];
    }

    /** @return array{lifetime:int,recent:int,same_subject:int,same_surface:int} */
    public function counts(string $claimId, string $subjectId, string $surface): array
    {
        $records = array_values(array_filter($this->records, static fn (array $record): bool => $record['claim_id'] === $claimId));
        return [
            'lifetime' => count($records),
            'recent' => count($records),
            'same_subject' => count(array_filter($records, static fn (array $record): bool => $subjectId === '' || $record['subject_id'] === $subjectId)),
            'same_surface' => count(array_filter($records, static fn (array $record): bool => $surface === '' || $record['surface'] === $surface)),
        ];
    }
}
