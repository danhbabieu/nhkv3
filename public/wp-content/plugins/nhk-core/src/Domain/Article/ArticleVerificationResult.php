<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final readonly class ArticleVerificationResult
{
    /** @param list<string> $reasons */
    public function __construct(public bool $verified, public array $reasons = []) {}
}
