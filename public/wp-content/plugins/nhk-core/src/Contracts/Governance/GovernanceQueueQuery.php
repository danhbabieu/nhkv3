<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

interface GovernanceQueueQuery
{
    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function page(array $filters = []): array;
}
