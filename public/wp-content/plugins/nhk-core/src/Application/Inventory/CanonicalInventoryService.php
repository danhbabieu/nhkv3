<?php
declare(strict_types=1);

namespace NHK\Core\Application\Inventory;

final class CanonicalInventoryService
{
    /** @param array<string,callable():list<array<string,mixed>>> $providers */
    public function __construct(private array $providers) {}

    public function inventory(array $filters, int $limit = 50, ?string $after = null): InventoryPage
    {
        $limit = max(1, min(10000, $limit));
        $type = isset($filters['type']) ? trim((string) $filters['type']) : null;
        if ($type !== null && $type !== '' && !isset($this->providers[$type])) return new InventoryPage([], 0, null);
        $types = $type !== null && $type !== '' ? [$type] : array_keys($this->providers);
        $rows = [];
        foreach ($types as $rowType) {
            $records = ($this->providers[$rowType])();
            foreach ($records as $record) {
                $row = $this->normalize($rowType, $record);
                if (!$this->matches($row, $filters)) continue;
                $rows[] = $row;
            }
        }
        usort($rows, static fn (array $left, array $right): int => [$left['type'], $left['uuid']] <=> [$right['type'], $right['uuid']]);
        if ($after !== null && $after !== '') $rows = array_values(array_filter($rows, static fn (array $row): bool => self::cursor($row) > $after));
        $total = count($rows);
        $items = array_slice($rows, 0, $limit);
        $next = count($rows) > $limit ? self::cursor($items[count($items) - 1]) : null;
        return new InventoryPage($items, $total, $next);
    }

    /** @return array<string,mixed> */
    private function normalize(string $type, array $record): array
    {
        $state = strtoupper(trim((string) ($record['state'] ?? ($record['active'] ?? true ? 'ACTIVE' : 'RETIRED'))));
        $active = $state === 'ACTIVE';
        return [
            'type' => $type,
            'uuid' => (string) ($record['uuid'] ?? $record['canonical_id'] ?? $record['id'] ?? ''),
            'stable_key' => (string) ($record['stable_key'] ?? ''),
            'revision' => (int) ($record['revision'] ?? 1),
            'state' => $state,
            'active' => $active,
            'provenance' => is_array($record['provenance'] ?? null) ? $record['provenance'] : [],
            'visibility' => isset($record['visibility']) ? strtoupper((string) $record['visibility']) : null,
        ];
    }

    private function matches(array $row, array $filters): bool
    {
        foreach (['type', 'state', 'visibility', 'stable_key', 'uuid'] as $key) {
            if (isset($filters[$key]) && (string) $filters[$key] !== '' && (string) $row[$key] !== (string) $filters[$key]) return false;
        }
        if (array_key_exists('active', $filters) && (bool) $row['active'] !== (bool) $filters['active']) return false;
        return true;
    }

    private static function cursor(array $row): string
    {
        return $row['type'] . ':' . $row['uuid'];
    }
}
