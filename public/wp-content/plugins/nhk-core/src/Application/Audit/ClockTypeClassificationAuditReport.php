<?php
declare(strict_types=1);

namespace NHK\Core\Application\Audit;

final readonly class ClockTypeClassificationAuditReport
{
    /** @param array<string,mixed> $targetInventory @param list<array<string,mixed>> $results @param array<string,int> $resultCounts @param array<string,mixed> $pagination @param array<string,list<array<string,mixed>>> $samples @param array<string,int> $auditedCounts */
    public function __construct(
        public array $targetInventory,
        public array $results,
        public array $resultCounts,
        public array $pagination,
        public string $fingerprint,
        public array $samples = [],
        public array $auditedCounts = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'audit' => 'legacy_clock_type_classification_dry_run',
            'read_only' => true,
            'target_inventory' => $this->targetInventory,
            'results' => $this->results,
            'result_counts' => $this->resultCounts,
            'audited_counts' => $this->auditedCounts,
            'pagination' => $this->pagination,
            'samples' => $this->samples,
            'fingerprint' => $this->fingerprint,
        ];
    }

    public function summary(): string
    {
        $lines = [
            '# Clock-Type legacy classification dry-run',
            '',
            'Read-only audit; no Proposal, Graph, Authority, Knowledge, Media, Video or Public Identity writer is invoked.',
            '',
            '## Result counts',
        ];
        foreach ($this->resultCounts as $status => $count) $lines[] = sprintf('- %s: %d', $status, $count);
        $lines[] = '';
        $lines[] = 'Owner-review samples: ' . count(array_merge(...array_values($this->samples))) . ' safe rows';
        $lines[] = 'Fingerprint: `' . $this->fingerprint . '`';
        return implode("\n", $lines) . "\n";
    }
}
