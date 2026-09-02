<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

final readonly class PublicEligibilityResult
{
    /** @param list<string> $reasons @param list<string> $warnings */
    public function __construct(public bool $eligible, public array $reasons = [], public array $warnings = []) {}

    public static function eligible(): self { return new self(true); }

    public static function blocked(string ...$reasons): self { return new self(false, array_values(array_unique($reasons))); }

    public function withWarning(string $warning): self
    {
        return new self($this->eligible, $this->reasons, array_values(array_unique([...$this->warnings, $warning])));
    }
}
