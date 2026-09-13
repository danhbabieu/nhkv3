<?php
declare(strict_types=1);

namespace NHK\Core\Application\Runtime;

final class SemanticWritePolicyViolation extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode, string $message, public readonly array $decision = [])
    {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['code' => $this->reasonCode, 'reason' => $this->reasonCode] + $this->decision;
    }
}
