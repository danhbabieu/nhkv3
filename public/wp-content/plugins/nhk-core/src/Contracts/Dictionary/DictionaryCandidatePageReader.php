<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Dictionary;

interface DictionaryCandidatePageReader
{
    /**
     * @return array{
     *     items:list<object>,
     *     total:int,
     *     has_more:bool,
     *     next_cursor:?string,
     *     diagnostics?:list<array<string,mixed>>
     * }
     */
    public function pageForReview(int $limit = 100, ?string $cursor = null, ?string $state = null): array;
}
