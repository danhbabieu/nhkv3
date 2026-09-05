<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Dictionary;

use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};

interface DictionaryConceptRepository
{
    public function findById(string $conceptId): ?DictionaryConcept;
    public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array;
    public function listApproved(int $limit = 500): array;
    public function listLabels(string $conceptId, bool $includeInactive = false): array;
    public function createConcept(DictionaryConcept $concept): DictionaryConcept;
    public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept;
    public function addLabel(DictionaryLabel $label): DictionaryLabel;
}
