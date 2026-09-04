<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Dictionary;

final readonly class DictionaryLabel
{
    public const PREFERRED = 'PREFERRED';
    public const ALTERNATE = 'ALTERNATE';
    public const COLLOQUIAL = 'COLLOQUIAL';
    public const TECHNICAL = 'TECHNICAL';
    public const PHONETIC = 'PHONETIC';
    public const HIDDEN = 'HIDDEN';

    public function __construct(
        public string $conceptId,
        public string $label,
        public string $normalizedLabel,
        public string $kind = self::ALTERNATE,
        public ?string $locale = null,
        public array $context = [],
        public bool $active = true,
    ) {
        if (trim($conceptId) === '' || trim($label) === '' || trim($normalizedLabel) === '') throw new \InvalidArgumentException('Dictionary label identity is required.');
        if (!in_array($kind, [self::PREFERRED, self::ALTERNATE, self::COLLOQUIAL, self::TECHNICAL, self::PHONETIC, self::HIDDEN], true)) throw new \InvalidArgumentException('Invalid dictionary label kind.');
    }
}
