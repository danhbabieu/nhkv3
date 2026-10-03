<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Dictionary;

final readonly class LexicalEntryForm
{
    public const PREFERRED = 'PREFERRED';
    public const ALTERNATE = 'ALTERNATE';
    public const COLLOQUIAL = 'COLLOQUIAL';
    public const TECHNICAL = 'TECHNICAL';
    public const PHONETIC = 'PHONETIC';

    public function __construct(
        public string $entryId,
        public string $form,
        public string $normalizedForm,
        public string $kind = self::ALTERNATE,
        public ?string $locale = null,
        public array $context = [],
        public bool $active = true,
    ) {
        if (trim($entryId) === '' || trim($form) === '' || trim($normalizedForm) === '') throw new \InvalidArgumentException('Lexical entry form is incomplete.');
    }
}
