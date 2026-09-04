<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Dictionary;

final readonly class DictionaryConcept
{
    public const DRAFT = 'DRAFT';
    public const APPROVED = 'APPROVED';
    public const RETIRED = 'RETIRED';

    public function __construct(
        public string $conceptId,
        public string $preferredLabel,
        public string $definition,
        public string $status = self::DRAFT,
        public ?string $destinationType = null,
        public ?string $destinationId = null,
        public ?string $destinationUrl = null,
        public array $context = [],
        public int $revision = 1,
    ) {
        if (trim($conceptId) === '' || trim($preferredLabel) === '') throw new \InvalidArgumentException('Dictionary concept identity and preferred label are required.');
        if (!in_array($status, [self::DRAFT, self::APPROVED, self::RETIRED], true) || $revision < 1) throw new \InvalidArgumentException('Invalid dictionary concept state.');
    }

    public function approved(): bool
    {
        return $this->status === self::APPROVED;
    }
}
