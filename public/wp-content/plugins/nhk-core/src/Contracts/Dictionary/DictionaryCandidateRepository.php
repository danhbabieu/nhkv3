<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Dictionary;

use NHK\Core\Domain\Dictionary\DictionaryCandidate;

interface DictionaryCandidateRepository
{
    public function upsertObservation(DictionaryCandidate $candidate): DictionaryCandidate;
    public function suppressed(string $normalizedTerm, string $contextHash): bool;
    public function listForReview(int $limit = 100): array;
    public function findById(string $candidateId): ?DictionaryCandidate;
    public function saveDecision(DictionaryCandidate $candidate, int $expectedRevision): DictionaryCandidate;
}
