<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\ProposalDiscoveryReader;

final class ProposalDiscoveryService
{
    public const MAX_LIMIT = 50;

    public function __construct(private ProposalDiscoveryReader $reader) {}

    /** @return array{status:string,items:list<array<string,mixed>>,count:int,limit:int} */
    public function discover(array $input): array
    {
        $limit = min(self::MAX_LIMIT, max(1, (int) ($input['limit'] ?? 20)));
        $selectors = $input;
        unset($selectors['limit']);
        $selectors = $this->normalizeSelectors($selectors);
        $items = [];
        foreach ($this->reader->discover($selectors, $limit) as $row) {
            $items[] = [
                'proposal_id' => (string) ($row['proposal_id'] ?? ''),
                'entity_type' => (string) ($row['entity_type'] ?? ''),
                'operation' => (string) ($row['operation'] ?? ''),
                'state' => (string) ($row['state'] ?? $row['status'] ?? ''),
                'capture_id' => $row['capture_id'] ?? null,
                'entity_id' => $row['entity_id'] ?? null,
                'idempotency_key' => $row['idempotency_key'] ?? null,
            ];
        }
        return ['status' => $items === [] ? 'not_found' : 'found', 'items' => $items, 'count' => count($items), 'limit' => $limit];
    }

    /** @return array<string,string> */
    private function normalizeSelectors(array $selectors): array
    {
        $allowed = ['proposal_id', 'capture_id', 'entity_type', 'entity_id', 'idempotency_key'];
        if (array_diff(array_keys($selectors), $allowed) !== []) throw new \InvalidArgumentException('PROPOSAL_DISCOVERY_SELECTOR_INVALID');
        $hasEntity = array_key_exists('entity_type', $selectors) || array_key_exists('entity_id', $selectors);
        $keys = array_values(array_filter($allowed, static fn (string $key): bool => array_key_exists($key, $selectors)));
        if ($hasEntity) {
            if (count($keys) !== 2 || !array_key_exists('entity_type', $selectors) || !array_key_exists('entity_id', $selectors)) throw new \InvalidArgumentException('PROPOSAL_DISCOVERY_SELECTOR_EXACT');
        } elseif (count($keys) !== 1) {
            throw new \InvalidArgumentException('PROPOSAL_DISCOVERY_SELECTOR_EXACT');
        }
        $normalized = [];
        foreach ($selectors as $key => $value) {
            $value = is_string($value) ? trim($value) : '';
            if ($value === '' || strlen($value) > 191) throw new \InvalidArgumentException('PROPOSAL_DISCOVERY_SELECTOR_VALUE_INVALID');
            $normalized[$key] = $value;
        }
        return $normalized;
    }
}
