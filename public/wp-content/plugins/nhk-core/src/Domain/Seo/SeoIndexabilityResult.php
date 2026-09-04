<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Seo;

final readonly class SeoIndexabilityResult
{
    /** @param list<string> $reasons */
    public function __construct(private bool $indexable, private array $reasons = []) {}
    public function indexable(): bool { return $this->indexable; }
    public function reasons(): array { return $this->reasons; }
}
