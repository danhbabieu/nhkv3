<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Dictionary;

use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry, LexicalEntryForm};

interface DictionaryEntryRepository
{
    /** @return list<LexicalEntry> */
    public function findByForm(string $normalizedForm, array $context = []): array;
    public function findForConcept(string $conceptId): ?LexicalEntry;
    /** @return list<DictionaryConcept> */
    public function listSenses(LexicalEntry $entry, array $context = []): array;
    public function addForm(LexicalEntryForm $form): LexicalEntryForm;
}
