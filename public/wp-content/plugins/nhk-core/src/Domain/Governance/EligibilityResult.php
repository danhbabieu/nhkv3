<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Governance;

final readonly class EligibilityResult
{
    public function __construct(public bool $ready, public array $reasons = []) {}
    public static function ready(): self { return new self(true); }
    public static function blocked(string ...$reasons): self { return new self(false, array_values(array_unique($reasons))); }
}
