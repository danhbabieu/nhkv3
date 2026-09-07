<?php
declare(strict_types=1);

namespace NHK\Core\Application\Inventory;

final readonly class InventoryPage
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(public array $items, public int $total, public ?string $next) {}

    /** @return array{items:list<array<string,mixed>>,total:int,next:?string} */
    public function toArray(): array
    {
        return ['items' => $this->items, 'total' => $this->total, 'next' => $this->next];
    }
}
