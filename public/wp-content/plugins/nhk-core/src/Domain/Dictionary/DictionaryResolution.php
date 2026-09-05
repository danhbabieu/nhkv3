<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Dictionary;

final readonly class DictionaryResolution
{
    public const RESOLVED = 'RESOLVED';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const UNKNOWN = 'UNKNOWN';
    public const SUPPRESSED = 'SUPPRESSED';

    public function __construct(
        public string $status,
        public string $term,
        public string $normalizedTerm,
        public ?string $conceptId = null,
        public ?string $preferredLabel = null,
        public ?string $destinationType = null,
        public ?string $destinationId = null,
        public ?string $destinationUrl = null,
        public array $candidates = [],
        public array $context = [],
    ) {}

    public function resolved(): bool
    {
        return $this->status === self::RESOLVED;
    }
}
