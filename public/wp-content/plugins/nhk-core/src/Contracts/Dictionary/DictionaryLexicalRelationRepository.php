<?php
declare(strict_types=1);
namespace NHK\Core\Contracts\Dictionary;
use NHK\Core\Domain\Dictionary\DictionaryLexicalRelation;
interface DictionaryLexicalRelationRepository
{
    public function create(DictionaryLexicalRelation $relation): DictionaryLexicalRelation;
    public function findByUuid(string $uuid): ?DictionaryLexicalRelation;
    public function findByIdempotencyKey(string $key): ?DictionaryLexicalRelation;
    public function update(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation;
    public function retire(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation;
    public function reactivate(DictionaryLexicalRelation $relation, int $expectedRevision): DictionaryLexicalRelation;
    /** @return array{items:list<DictionaryLexicalRelation>,next_cursor:?int} */
    public function listForEntry(string $entryUuid, int $afterId=0, int $limit=100, bool $includeRetired=false): array;
}
