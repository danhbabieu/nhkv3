<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Domain\Graph\NodeReference;

final class ProjectionBackfillService
{
    public function __construct(private ClaimProjectionService $projections) {}

    /** @param list<array{uuid:string,type:string,canonical_url?:string,h1?:string}> $nodes @return array<string,mixed> */
    public function run(array $nodes, bool $dryRun = true, int $batchSize = 50, int $cursor = 0): array
    {
        $batchSize = min(500, max(1, $batchSize)); $cursor = max(0, $cursor); $selected = array_slice($nodes, $cursor, $batchSize); $results = []; $failed = 0;
        foreach ($selected as $index => $node) {
            try {
                $uuid = trim((string) ($node['uuid'] ?? '')); $type = trim((string) ($node['type'] ?? ''));
                if ($uuid === '' || $type === '') throw new \InvalidArgumentException('BACKFILL_NODE_INVALID');
                if ($dryRun) $results[] = ['uuid' => $uuid, 'type' => $type, 'status' => 'would_rebuild'];
                else $results[] = ['uuid' => $uuid, 'type' => $type, 'status' => 'rebuilt', 'revision' => $this->projections->rebuild(new NodeReference($type, $uuid), (string) ($node['canonical_url'] ?? ''), (string) ($node['h1'] ?? ''))->revision];
            } catch (\Throwable $error) { $failed++; $results[] = ['uuid' => (string) ($node['uuid'] ?? ''), 'status' => 'failed', 'reason' => $error->getMessage()]; }
        }
        return ['status' => $failed === 0 ? 'complete' : 'partial', 'dry_run' => $dryRun, 'cursor' => $cursor, 'next_cursor' => $cursor + count($selected) < count($nodes) ? $cursor + count($selected) : null, 'batch_size' => $batchSize, 'processed' => count($selected), 'failed' => $failed, 'results' => $results];
    }
}
