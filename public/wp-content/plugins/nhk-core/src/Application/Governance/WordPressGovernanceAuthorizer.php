<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Contracts\Governance\GovernanceAuthorizer;

final class WordPressGovernanceAuthorizer implements GovernanceAuthorizer
{
    public function require(string $capability): void
    {
        GovernanceCapabilities::require($capability);
    }
}
