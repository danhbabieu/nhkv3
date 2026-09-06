<?php
declare(strict_types=1);
namespace NHK\Core\Application\Graph;

final class RelationBackfillReport
{
    /** @param list<RelationBackfillCandidate> $proposed @param list<array<string,mixed>> $ambiguous */
    public function __construct(public array $proposed = [], public array $ambiguous = [], public int $scanned = 0, public int $existing = 0, public int $created = 0, public int $skippedIdempotent = 0, public int $unsupported = 0, public int $evidenceGap = 0, public int $registryGap = 0, public int $errors = 0, public bool $zeroChangeOnSecondRun = false) {}

    /** @return array<string,mixed> */
    public function toArray(): array { return ['TOTAL_RECORDS_SCANNED' => $this->scanned, 'RELATIONS_EXISTING' => $this->existing, 'RELATIONS_PROPOSED' => count($this->proposed), 'RELATIONS_CREATED' => $this->created, 'RELATIONS_SKIPPED_IDEMPOTENT' => $this->skippedIdempotent, 'AMBIGUOUS' => count($this->ambiguous), 'UNSUPPORTED_ENDPOINT' => $this->unsupported, 'EVIDENCE_GAP' => $this->evidenceGap, 'REGISTRY_GAP' => $this->registryGap, 'ERRORS' => $this->errors, 'ZERO_CHANGE_ON_SECOND_RUN' => $this->zeroChangeOnSecondRun]; }
}
