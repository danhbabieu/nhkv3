<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Article;

interface ArticleApplyService
{
    /** @return array<string,mixed> */
    public function apply(string $proposalId): array;
}
