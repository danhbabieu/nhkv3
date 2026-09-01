<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Authority;

use RuntimeException;

final class MalformedAuthorityRow extends RuntimeException
{
    public function __construct(string $reason, public readonly ?string $stableKey = null, public readonly string $reasonCode = 'INVALID_ROW')
    {
        parent::__construct($reason);
    }
}
