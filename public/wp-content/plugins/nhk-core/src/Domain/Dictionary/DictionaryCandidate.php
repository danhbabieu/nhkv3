<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Dictionary;

final readonly class DictionaryCandidate
{
    public function __construct(
        public string $candidateId,
        public string $normalizedTerm,
        public string $contextHash,
        public array $rawForms,
        public string $state = DictionaryCandidateState::DETECTED,
        public array $context = [],
        public array $suggestions = [],
        public int $occurrences = 1,
        public string $firstSeenAt = '',
        public string $lastSeenAt = '',
        public int $revision = 1,
    ) {
        if (trim($candidateId) === '' || trim($normalizedTerm) === '' || preg_match('/^[a-f0-9]{64}$/', $contextHash) !== 1) throw new \InvalidArgumentException('Invalid dictionary candidate identity.');
        if (!DictionaryCandidateState::valid($state) || $occurrences < 1 || $revision < 1) throw new \InvalidArgumentException('Invalid dictionary candidate state.');
    }

    public function suppressed(): bool
    {
        return DictionaryCandidateState::suppressed($this->state);
    }
}
