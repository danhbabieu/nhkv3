<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Dictionary;

final readonly class DictionaryMention
{
    public function __construct(
        public string $mentionId,
        public string $fingerprint,
        public string $sourceKind,
        public string $sourceId,
        public string $normalizedTerm,
        public string $contextHash,
        public ?string $conceptId = null,
        public array $context = [],
        public string $strength = 'NORMAL',
        public string $createdAt = '',
    ) {
        if (trim($mentionId) === '' || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1 || trim($sourceKind) === '' || trim($sourceId) === '' || trim($normalizedTerm) === '') throw new \InvalidArgumentException('Invalid dictionary mention.');
        if (!in_array($strength, ['WEAK', 'NORMAL', 'STRONG'], true)) throw new \InvalidArgumentException('Invalid dictionary mention strength.');
    }
}
