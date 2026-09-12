<?php
declare(strict_types=1);

namespace NHK\Core\Application\Audit;

final readonly class ClockTypeClassificationAuditReport
{
    /** @param array<string,mixed> $targetInventory @param list<array<string,mixed>> $results @param array<string,int> $resultCounts @param array<string,mixed> $pagination */
    public function __construct(
        public array $targetInventory,
        public array $results,
        public array $resultCounts,
        public array $pagination,
        public string $fingerprint,
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
            'pagination' => $this->pagination,
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
        $lines[] = 'Fingerprint: `' . $this->fingerprint . '`';
        return implode("\n", $lines) . "\n";
    }
}
