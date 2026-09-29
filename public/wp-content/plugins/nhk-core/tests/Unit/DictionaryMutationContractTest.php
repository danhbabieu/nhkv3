<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryMutationService;
use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};
use PHPUnit\Framework\TestCase;

final class DictionaryMutationContractTest extends TestCase
{
    public function test_concept_update_is_revision_bound_and_idempotent(): void
    {
        $repo = new class implements DictionaryConceptRepository {
            public DictionaryConcept $concept;
            public function __construct() { $this->concept = new DictionaryConcept('concept-1', 'Côn máng', 'Cũ', DictionaryConcept::DRAFT, null, null, null, ['public_slug' => 'con-mang'], 1); }
            public function findById(string $conceptId): ?DictionaryConcept { return $conceptId === $this->concept->conceptId ? $this->concept : null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept
            {
                if ($this->concept->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_CONCEPT_REVISION_CONFLICT');
                return $this->concept = new DictionaryConcept($concept->conceptId, $concept->preferredLabel, $concept->definition, $concept->status, $concept->destinationType, $concept->destinationId, $concept->destinationUrl, $concept->context, $expectedRevision + 1);
            }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $receipts = [];
        $service = new DictionaryMutationService($repo, null, static function (string $key, string $fingerprint) use (&$receipts): ?array { return $receipts[$key] ?? null; }, static function (string $key, string $fingerprint, array $result) use (&$receipts): void { $receipts[$key] = ['fingerprint' => $fingerprint, 'result' => $result]; });

        $first = $service->updateConcept('concept-1', 1, 'Côn lòng máng', 'Mới', ['public_slug' => 'con-long-mang'], 'dictionary-edit-1');
        $replay = $service->updateConcept('concept-1', 1, 'Côn lòng máng', 'Mới', ['public_slug' => 'con-long-mang'], 'dictionary-edit-1');

        self::assertSame(2, $first['concept']->revision);
        self::assertSame($first['concept']->revision, $replay['concept']->revision);
        $this->expectExceptionMessage('DICTIONARY_IDEMPOTENCY_CONFLICT');
        $service->updateConcept('concept-1', 2, 'Khác', 'Khác', ['public_slug' => 'khac'], 'dictionary-edit-1');
    }

    public function test_retire_and_reactivate_are_soft_lifecycle_operations(): void
    {
        $repo = new class implements DictionaryConceptRepository {
            public DictionaryConcept $concept;
            public function __construct() { $this->concept = new DictionaryConcept('concept-1', 'Côn máng', 'Định nghĩa', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'con-mang'], 1); }
            public function findById(string $conceptId): ?DictionaryConcept { return $this->concept; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return [$this->concept]; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $this->concept = new DictionaryConcept($concept->conceptId, $concept->preferredLabel, $concept->definition, $concept->status, $concept->destinationType, $concept->destinationId, $concept->destinationUrl, $concept->context, $expectedRevision + 1); }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $service = new DictionaryMutationService($repo);
        $retired = $service->setConceptStatus('concept-1', 1, DictionaryConcept::RETIRED, 'retire-1');
        self::assertSame(DictionaryConcept::RETIRED, $retired['concept']->status);
        self::assertSame(2, $retired['concept']->revision);
        $reactivated = $service->setConceptStatus('concept-1', 2, DictionaryConcept::APPROVED, 'reactivate-1');
        self::assertSame(DictionaryConcept::APPROVED, $reactivated['concept']->status);
        self::assertSame(3, $reactivated['concept']->revision);
    }
}
