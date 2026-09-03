<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

final class OperationCompatibilityException extends \InvalidArgumentException
{
    public function __construct(public readonly string $diagnosticCode, string $message)
    {
        parent::__construct($diagnosticCode . ': ' . $message);
    }
}
