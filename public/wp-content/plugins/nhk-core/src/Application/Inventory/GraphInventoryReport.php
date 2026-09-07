<?php
declare(strict_types=1);

namespace NHK\Core\Application\Inventory;

final readonly class GraphInventoryReport
{
    /** @param list<array<string,mixed>> $items @param array<string,int> $counters */
    public function __construct(public array $items, public int $total, public ?string $next, public array $counters) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => $this->total, 'next' => $this->next, 'counters' => $this->counters];
    }
}
