<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

final class SemanticContextPackage
{
    /** @param array<string,mixed> $value */
    public function __construct(private array $value = []) {}

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->value; }

    public function with(string $key, mixed $value): self
    {
        $next = $this->value;
        $next[$key] = $value;
        return new self($next);
    }

    public function get(string $key, mixed $default = null): mixed { return $this->value[$key] ?? $default; }
}
