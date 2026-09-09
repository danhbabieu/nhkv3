<?php
declare(strict_types=1);

namespace NHK\Core\Application\Inventory;

final readonly class GraphInventoryReport
{
    /** @param list<array<string,mixed>> $items @param array<string,int> $counters */
    public function __construct(public array $items, public int $total, public ?string $next, public array $counters, public ?string $reason = null) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $result = ['items' => $this->items, 'total' => $this->total, 'next' => $this->next, 'counters' => $this->counters];
        if ($this->reason !== null) $result['reason'] = $this->reason;
        return $result;
    }
}
