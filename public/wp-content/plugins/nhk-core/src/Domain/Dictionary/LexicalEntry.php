<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Dictionary;

final readonly class LexicalEntry
{
    public function __construct(
        public string $entryId,
        public string $preferredForm,
        public string $normalizedPreferredForm,
        public string $status = DictionaryConcept::DRAFT,
        public ?string $locale = null,
        public array $context = [],
        public int $revision = 1,
        public array $senseIds = [],
    ) {
        if (trim($entryId) === '' || trim($preferredForm) === '' || trim($normalizedPreferredForm) === '' || $revision < 1) {
            throw new \InvalidArgumentException('Lexical entry identity and preferred form are required.');
        }
    }
}
