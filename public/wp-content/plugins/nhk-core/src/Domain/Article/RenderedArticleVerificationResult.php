<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class RenderedArticleVerificationResult
{
    /** @param array<string,bool> $checks @param list<string> $reasons */
    public function __construct(
        public string $runtime,
        public bool $verified,
        public array $checks = [],
        public array $reasons = [],
        public ?string $route = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['runtime' => $this->runtime, 'verified' => $this->verified, 'checks' => $this->checks, 'reasons' => $this->reasons, 'route' => $this->route];
    }
}
