<?php
declare(strict_types=1);
namespace NHK\Core\Application\Graph;

/** Generic dry-run/apply coordinator; resolution is injected so text matching cannot become canonical truth accidentally. */
final class RelationBackfillService
{
    /** @param callable(array<string,mixed>): (RelationBackfillCandidate|array<string,mixed>|null) $resolver @param callable(RelationBackfillCandidate): bool $exists @param callable():list<array<string,mixed>>|null $recordProvider */
    public function __construct(private $resolver, private $exists, private $recordProvider = null) {}

    /** @param list<array<string,mixed>> $records */
    public function dryRun(array $records): RelationBackfillReport
    {
        if ($records === [] && is_callable($this->recordProvider)) $records = ($this->recordProvider)();
        $report = new RelationBackfillReport(scanned: count($records));
        foreach ($records as $record) {
            $resolved = ($this->resolver)($record);
            if ($resolved instanceof RelationBackfillCandidate) {
                if (($this->exists)($resolved)) { $report->existing++; $report->counters['EXISTING']++; }
                else { $report->proposed[] = $resolved; $report->candidates[] = $resolved->toArray(); $report->counters['MISSING_DETERMINISTIC']++; }
                continue;
            }
            if (!is_array($resolved)) { $report->unsupported++; continue; }
            $status = strtoupper((string) ($resolved['status'] ?? 'NOT_APPLICABLE'));
            if (!array_key_exists($status, $report->counters)) { $report->unsupported++; continue; }
            $report->counters[$status]++;
            if ($status === 'AMBIGUOUS') $report->ambiguous[] = $resolved;
            if (isset($resolved['candidate']) && is_array($resolved['candidate'])) $report->candidates[] = $resolved['candidate'];
            if ($status === 'EVIDENCE_GAP') $report->evidenceGap++;
            if ($status === 'REGISTRY_GAP') $report->registryGap++;
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
