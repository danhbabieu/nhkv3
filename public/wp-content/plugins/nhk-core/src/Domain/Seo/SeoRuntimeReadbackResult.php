<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Seo;

final readonly class SeoRuntimeReadbackResult
{
    /** @param array<string,mixed> $observed @param list<string> $mismatches */
    public function __construct(private string $status, private array $observed = [], private array $mismatches = []) {}
    public function status(): string { return $this->status; }
    public function observed(): array { return $this->observed; }
    public function mismatches(): array { return $this->mismatches; }
}
