<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class ArticlePublicationGateResult
{
    /** @param list<string> $blockers */
    public function __construct(public bool $eligible, public array $blockers = []) {}

    /** @return array{eligible:bool,blockers:list<string>} */
    public function toArray(): array
    {
        return ['eligible' => $this->eligible, 'blockers' => array_values(array_unique($this->blockers))];
    }
}
