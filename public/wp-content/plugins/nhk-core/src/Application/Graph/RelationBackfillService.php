<?php
declare(strict_types=1);
namespace NHK\Core\Application\Graph;

/** Generic dry-run/apply coordinator; resolution is injected so text matching cannot become canonical truth accidentally. */
final class RelationBackfillService
{
    /** @param callable(array<string,mixed>): (RelationBackfillCandidate|array<string,mixed>|null) $resolver @param callable(RelationBackfillCandidate): bool $exists */
    public function __construct(private $resolver, private $exists) {}

    /** @param list<array<string,mixed>> $records */
    public function dryRun(array $records): RelationBackfillReport
    {
        $report = new RelationBackfillReport(scanned: count($records));
        foreach ($records as $record) {
            $resolved = ($this->resolver)($record);
            if ($resolved instanceof RelationBackfillCandidate) {
                if (($this->exists)($resolved)) $report->existing++;
                else $report->proposed[] = $resolved;
                continue;
            }
            if (!is_array($resolved)) { $report->unsupported++; continue; }
            $status = (string) ($resolved['status'] ?? 'unsupported');
            if ($status === 'ambiguous') $report->ambiguous[] = $resolved;
            elseif ($status === 'evidence_gap') $report->evidenceGap++;
            elseif ($status === 'registry_gap') $report->registryGap++;
            else $report->unsupported++;
        }
        return $report;
    }

    /** @param list<RelationBackfillCandidate> $candidates @param callable(RelationBackfillCandidate): string $apply */
    public function apply(array $candidates, callable $apply): RelationBackfillReport
    {
        $report = new RelationBackfillReport(proposed: $candidates);
        foreach ($candidates as $candidate) {
            if (($this->exists)($candidate)) { $report->existing++; $report->skippedIdempotent++; continue; }
            try { $result = $apply($candidate); if (!in_array($result, ['created', 'skipped_idempotent'], true)) throw new \RuntimeException('BACKFILL_APPLY_RESULT_INVALID'); if ($result === 'created') $report->created++; else $report->skippedIdempotent++; }
            catch (\Throwable) { $report->errors++; }
        }
        $report->zeroChangeOnSecondRun = $report->created === 0 && $report->errors === 0;
        return $report;
    }
}
