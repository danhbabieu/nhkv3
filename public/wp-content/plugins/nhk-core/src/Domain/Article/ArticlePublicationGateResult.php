<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class ArticlePublicationGateResult
{
    /** @param list<string> $blockers @param list<string> $warnings */
    public function __construct(public bool $eligible, public array $blockers = [], public array $warnings = []) {}

    /** @return array{eligible:bool,blockers:list<string>,warnings:list<string>} */
    public function toArray(): array
    {
        return ['eligible' => $this->eligible, 'blockers' => array_values(array_unique($this->blockers)), 'warnings' => array_values(array_unique($this->warnings))];
    }
}
