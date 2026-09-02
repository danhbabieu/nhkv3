<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Contracts\Graph\GraphDistributionReader;
use NHK\Core\Domain\Graph\PredicateRegistry;

final class GraphDistributionAudit
{
    public function __construct(private GraphDistributionReader $reader, private ?PredicateRegistry $predicates = null) {}

    /** @return array{distribution:list<array{source_type:string,predicate:string,target_type:string,edge_count:int}>,active_edge_total:int,registered_predicate_count:int} */
    public function read(): array
    {
        $groups = [];
        foreach ($this->reader->rows() as $row) {
            foreach (['source_type', 'predicate', 'target_type'] as $field) if (!isset($row[$field]) || !is_string($row[$field]) || trim($row[$field]) === '') throw new \RuntimeException('GRAPH_DISTRIBUTION_ROW_INVALID');
            $count = $row['edge_count'] ?? 1;
            if (!is_int($count) || $count < 1) throw new \RuntimeException('GRAPH_DISTRIBUTION_COUNT_INVALID');
            $key = $row['source_type'] . "\0" . $row['predicate'] . "\0" . $row['target_type'];
            $groups[$key] = ($groups[$key] ?? 0) + $count;
        }
        $distribution = [];
        foreach ($groups as $key => $count) {
            [$source, $predicate, $target] = explode("\0", $key);
            $distribution[] = ['source_type' => $source, 'predicate' => $predicate, 'target_type' => $target, 'edge_count' => $count];
        }
        usort($distribution, static fn (array $a, array $b): int => [$a['source_type'], $a['predicate'], $a['target_type']] <=> [$b['source_type'], $b['predicate'], $b['target_type']]);
        return ['distribution' => $distribution, 'active_edge_total' => array_sum(array_column($distribution, 'edge_count')), 'registered_predicate_count' => $this->predicates ? count($this->predicates->all()) : 0];
    }
}
