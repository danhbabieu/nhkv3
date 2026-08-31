<?php
declare(strict_types=1);

namespace NHK\Tests\Support;

use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Domain\Governance\Proposal;

final class InMemoryProposalRepository implements ProposalRepository
{
    /** @var array<string, Proposal> */
    private array $items = [];
    public function create(Proposal $proposal): Proposal { $this->items[$proposal->id] = $proposal; return $proposal; }
    public function find(string $id): ?Proposal { return $this->items[$id] ?? null; }
    public function save(Proposal $proposal): Proposal { $this->items[$proposal->id] = $proposal; return $proposal; }
}
