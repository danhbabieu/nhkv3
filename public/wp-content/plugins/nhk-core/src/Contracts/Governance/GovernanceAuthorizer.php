<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

interface GovernanceAuthorizer
{
    public function require(string $capability): void;
}
