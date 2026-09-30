<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/** Read-only, deterministic source page used by the bounded corpus audit. */
interface DictionaryCorpusSourceReader
{
    /** @return array{items:list<array<string,mixed>>,has_more:bool} */
    public function page(?string $after, int $limit): array;
}
