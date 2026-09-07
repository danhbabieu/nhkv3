<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Governance;

interface AutomationPolicyStorage
{
    /** @return array<string,string> */
    public function read(): array;

    /** @param array<string,string> $policies */
    public function write(array $policies): void;
}
