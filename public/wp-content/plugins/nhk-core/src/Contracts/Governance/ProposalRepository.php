<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

use NHK\Core\Domain\Governance\Proposal;

interface ProposalRepository
{
    public function create(Proposal $proposal): Proposal;
    public function find(string $id): ?Proposal;
    public function findByIdempotencyKey(string $key): ?Proposal;
    public function save(Proposal $proposal): Proposal;
}
