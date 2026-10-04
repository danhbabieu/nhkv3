<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryMutationService;
use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};
use NHK\Core\Domain\Dictionary\{LexicalEntry, LexicalEntryForm};
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

    public function test_entry_lifecycle_requires_entry_repository_and_returns_read_back(): void
    {
        $repo = new class implements DictionaryConceptRepository {
            public function findById(string $conceptId): ?DictionaryConcept { return new DictionaryConcept($conceptId, 'Côn', 'Nghĩa', DictionaryConcept::DRAFT); }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $entries = new class {
            public function createWithSense(LexicalEntry $entry, DictionaryConcept $sense, array $context): array { return ['entry' => $entry, 'sense' => $sense, 'forms' => []]; }
        };
        $service = new \NHK\Core\Application\Dictionary\DictionaryMutationService($repo, entryRepository: $entries);
        $result = $service->createEntryWithSense('Côn', 'Nghĩa', [], 'entry-create-1');
        self::assertInstanceOf(LexicalEntry::class, $result['entry']);
        self::assertInstanceOf(DictionaryConcept::class, $result['sense']);
        self::assertSame(1, $result['entry']->revision);
    }

    public function test_entry_write_fails_closed_when_entry_sense_schema_is_unavailable(): void
    {
        $repo = new class implements DictionaryConceptRepository {
            public function findById(string $conceptId): ?DictionaryConcept { return new DictionaryConcept($conceptId, 'Côn', 'Nghĩa', DictionaryConcept::DRAFT); }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $entries = new class { public function createWithSense(LexicalEntry $entry, DictionaryConcept $sense, array $context): array { throw new \LogicException('write must not reach repository'); } };
        $service = new DictionaryMutationService($repo, entryRepository: $entries, entrySenseReady: static fn (): bool => false);

        $this->expectExceptionMessage('DICTIONARY_ENTRY_SENSE_SCHEMA_UNAVAILABLE');
        $service->createEntryWithSense('Côn', 'Nghĩa', [], 'entry-schema-missing');
    }

    public function test_entry_form_collision_is_rejected_before_repository_write(): void
    {
        $repo = new class implements DictionaryConceptRepository {
            public function findById(string $conceptId): ?DictionaryConcept { return new DictionaryConcept($conceptId, 'Côn', 'Nghĩa', DictionaryConcept::DRAFT); }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $entries = new class { public int $writes = 0; public function addFormToEntry(string $id, int $revision, LexicalEntryForm $form): array { $this->writes++; return []; } };
        $service = new \NHK\Core\Application\Dictionary\DictionaryMutationService($repo, entryRepository: $entries);
        $this->expectExceptionMessage('DICTIONARY_ENTRY_FORM_COLLISION');
        $service->addFormToEntry('22222222-2222-7222-8222-222222222222', 1, 'CÔN', ['normalized_form' => 'con'], 'form-1');
    }

    public function test_entry_form_mutation_preserves_requested_locale_for_all_supported_form_locales(): void
    {
        $repo = new class implements DictionaryConceptRepository {
            public function findById(string $conceptId): ?DictionaryConcept { return new DictionaryConcept($conceptId, 'Côn', 'Nghĩa', DictionaryConcept::DRAFT); }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $entries = new class {
            public array $forms = [];
            public function findById(string $id): LexicalEntry { return new LexicalEntry($id, 'Côn', 'con', DictionaryConcept::DRAFT, 'vi-VN', [], 1); }
            public function addFormToEntry(string $id, int $revision, LexicalEntryForm $form): array
            {
                $this->forms[] = $form;
                return ['entry' => new LexicalEntry($id, 'Côn', 'con', DictionaryConcept::DRAFT, 'vi-VN', [], $revision + 1), 'form' => $form];
            }
        };
        $service = new DictionaryMutationService($repo, entryRepository: $entries);

        foreach ([['Selection cam', 'en'], ['Jaquemart', 'fr'], ['Jahresuhr/400', 'de'], ['400 ngày', 'vi-VN']] as [$form, $locale]) {
            $service->addFormToEntry('entry-' . $locale, 1, $form, [], 'form-' . $locale, LexicalEntryForm::ALTERNATE, $locale);
        }

        self::assertSame(['en', 'fr', 'de', 'vi-VN'], array_map(static fn (LexicalEntryForm $form): ?string => $form->locale, $entries->forms));
    }

    public function test_entry_form_rejects_unsupported_kind_before_repository_write(): void
    {
        $repo = new class implements DictionaryConceptRepository {
            public function findById(string $conceptId): ?DictionaryConcept { return new DictionaryConcept($conceptId, 'Côn', 'Nghĩa', DictionaryConcept::DRAFT); }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $service = new DictionaryMutationService($repo, entryRepository: new class { public function findById(string $id): LexicalEntry { return new LexicalEntry($id, 'Côn', 'côn'); } public function addFormToEntry(string $id, int $revision, LexicalEntryForm $form): array { throw new \LogicException('write must not reach repository'); } });
        $this->expectExceptionMessage('DICTIONARY_ENTRY_FORM_KIND_INVALID');
        $service->addFormToEntry('entry-1', 1, 'Jahresuhr/400', [], 'hidden-1', 'HIDDEN');
    }

    public function test_existing_entry_sense_mapping_can_update_semantic_reference_with_idempotency(): void
    {
        $concept = new DictionaryConcept('sense-1', '400 ngày', 'Loại đồng hồ.', DictionaryConcept::APPROVED);
        $repo = new class($concept) implements DictionaryConceptRepository {
            public function __construct(private DictionaryConcept $concept) {}
            public function findById(string $conceptId): ?DictionaryConcept { return $conceptId === $this->concept->conceptId ? $this->concept : null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $entries = new class {
            public int $calls = 0;
            public function setSenseSemanticReference(string $entryId, string $senseId, int $expectedRevision, string $type, string $id, ?int $revision): array
            {
                $this->calls++;
                return ['entry_id' => $entryId, 'sense_id' => $senseId, 'semantic_reference' => ['type' => $type, 'id' => $id, 'revision' => $revision], 'entry_revision' => $expectedRevision + 1];
            }
        };
        $receipts = [];
        $service = new DictionaryMutationService($repo, null, static function (string $key, string $fingerprint) use (&$receipts): ?array { return $receipts[$key] ?? null; }, static function (string $key, string $fingerprint, array $result) use (&$receipts): void { $receipts[$key] = ['fingerprint' => $fingerprint, 'result' => $result]; }, null, $entries, static fn (): bool => true);

        $first = $service->setSenseSemanticReference('entry-1', 'sense-1', 4, 'classification', 'owner-1', 9, 'mapping-1');
        $replay = $service->setSenseSemanticReference('entry-1', 'sense-1', 4, 'classification', 'owner-1', 9, 'mapping-1');

        self::assertSame($first['semantic_reference'], $replay['semantic_reference']);
        self::assertSame(5, $replay['entry_revision']);
        self::assertSame(1, $entries->calls);
    }
}
