<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryMutationService;
use NHK\Core\Application\Dictionary\DictionaryPreCreateResolver;
use NHK\Core\Contracts\Dictionary\{DictionaryConceptRepository, DictionaryEntryRepository};
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel, DictionaryPreCreateResolution};
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

    public function test_approving_a_sense_syncs_entry_public_eligibility_and_invalidates_only_once_on_replay(): void
    {
        $repo = new class implements DictionaryConceptRepository {
            public DictionaryConcept $concept;
            public function __construct() { $this->concept = new DictionaryConcept('concept-1', 'Kính rào', 'Nghĩa', DictionaryConcept::DRAFT, null, null, null, [], 1); }
            public function findById(string $conceptId): ?DictionaryConcept { return $this->concept; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $this->concept = new DictionaryConcept($concept->conceptId, $concept->preferredLabel, $concept->definition, $concept->status, null, null, null, $concept->context, $expectedRevision + 1); }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $entries = new class {
            public LexicalEntry $entry;
            public function __construct() { $this->entry = new LexicalEntry('entry-1', 'Kính rào', 'kính rào', DictionaryConcept::DRAFT, 'vi-VN', ['public_slug' => 'kinh-rao'], 1, ['concept-1']); }
            public function syncStatusForSense(string $senseId, string $status): LexicalEntry
            {
                return $this->entry = new LexicalEntry($this->entry->entryId, $this->entry->preferredForm, $this->entry->normalizedPreferredForm, $status, $this->entry->locale, $this->entry->context, $this->entry->revision + 1, $this->entry->senseIds);
            }
        };
        $receipts = [];
        $invalidations = 0;
        $service = new DictionaryMutationService(
            $repo,
            receiptReader: static function (string $key, string $fingerprint) use (&$receipts): ?array { return $receipts[$key] ?? null; },
            receiptWriter: static function (string $key, string $fingerprint, array $result) use (&$receipts): void { $receipts[$key] = ['fingerprint' => $fingerprint, 'result' => $result]; },
            entryRepository: $entries,
            cacheInvalidator: static function () use (&$invalidations): void { $invalidations++; },
        );

        $first = $service->setConceptStatus('concept-1', 1, DictionaryConcept::APPROVED, 'approve-entry-1');
        $replay = $service->setConceptStatus('concept-1', 1, DictionaryConcept::APPROVED, 'approve-entry-1');

        self::assertSame(DictionaryConcept::APPROVED, $first['entry']->status);
        self::assertSame($first['entry']->revision, $replay['entry']->revision);
        self::assertSame(1, $invalidations);
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
        $service = new \NHK\Core\Application\Dictionary\DictionaryMutationService($repo, entryRepository: $entries, preCreateResolver: new DictionaryPreCreateResolver($this->entryRepository()));
        $result = $service->createEntryWithSense('Côn', 'Nghĩa', [], 'entry-create-1');
        self::assertInstanceOf(LexicalEntry::class, $result['entry']);
        self::assertInstanceOf(DictionaryConcept::class, $result['sense']);
        self::assertSame(1, $result['entry']->revision);
    }

    public function test_direct_dictionary_create_fails_closed_without_a_pre_create_resolver(): void
    {
        $repo = $this->conceptRepository();
        $this->expectExceptionMessage('PRE_CREATE_RESOLUTION_REQUIRED');
        (new DictionaryMutationService($repo))->createDraft('Côn hoa thị', 'Nghĩa', [], 'direct-create-bypass');
    }

    public function test_new_entry_creation_persists_one_canonical_public_slug_before_read_back(): void
    {
        $repo = new class implements DictionaryConceptRepository {
            public function findById(string $conceptId): ?DictionaryConcept { return new DictionaryConcept($conceptId, 'Kính rào', 'Nghĩa', DictionaryConcept::DRAFT); }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $entries = new class {
            public ?LexicalEntry $written = null;
            public function createWithSense(LexicalEntry $entry, DictionaryConcept $sense, array $context): array
            {
                $this->written = $entry;
                return ['entry' => $entry, 'sense' => $sense, 'forms' => []];
            }
        };
        $writer = new \NHK\Core\Application\Dictionary\DictionaryEntryPublicIdentityWriter(static fn (string $slug, ?string $entryId = null): bool => false);
        $service = new DictionaryMutationService($repo, entryRepository: $entries, entryPublicIdentityWriter: $writer, preCreateResolver: new DictionaryPreCreateResolver($this->entryRepository()));

        $result = $service->createEntryWithSense('Kính rào', 'Nghĩa', [], 'entry-create-public-1');

        self::assertSame('kinh-rao', $result['entry']->context['public_slug']);
        self::assertSame('kinh-rao', $entries->written?->context['public_slug']);
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
        $this->expectExceptionMessage('PRE_CREATE_RESOLUTION_REQUIRED');
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
        $service = new DictionaryMutationService($repo, entryRepository: $entries, preCreateResolver: new DictionaryPreCreateResolver($this->formPreCreateRepository()));

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

    public function test_precreate_resolution_reuses_exact_entry_without_repository_write(): void
    {
        $sense = new DictionaryConcept('sense-existing', 'Côn hoa thị', 'Nghĩa', DictionaryConcept::APPROVED, null, null, null, [], 2);
        $entry = new LexicalEntry('entry-existing', 'Côn hoa thị', 'côn hoa thị', DictionaryConcept::APPROVED, 'vi-VN', [], 3, [$sense->conceptId]);
        $concepts = $this->conceptRepository([$sense]);
        $entries = $this->entryRepository([$entry], [$sense]);
        $service = new DictionaryMutationService($concepts, entryRepository: $entries, preCreateResolver: new DictionaryPreCreateResolver($entries));

        $result = $service->createEntryWithSense('Côn hoa thị', 'Không tạo lại', [], 'entry-reuse-1');

        self::assertSame('entry-existing', $result['entry']->entryId);
        self::assertSame('sense-existing', $result['sense']->conceptId);
        self::assertSame('REUSE_EXISTING', $result['resolution']['action']);
        self::assertSame(0, $entries->createWrites);
    }

    public function test_precreate_resolution_rejects_ambiguous_entry_creation(): void
    {
        $firstSense = new DictionaryConcept('sense-one', 'Côn', 'Máy', DictionaryConcept::APPROVED, null, null, null, ['domain' => 'máy'], 1);
        $secondSense = new DictionaryConcept('sense-two', 'Côn', 'Bút', DictionaryConcept::APPROVED, null, null, null, ['domain' => 'bút'], 1);
        $entry = new LexicalEntry('entry-ambiguous', 'Côn', 'côn', DictionaryConcept::APPROVED, 'vi-VN', [], 2, [$firstSense->conceptId, $secondSense->conceptId]);
        $entries = $this->entryRepository([$entry], [$firstSense, $secondSense]);
        $service = new DictionaryMutationService($this->conceptRepository([$firstSense, $secondSense]), entryRepository: $entries, preCreateResolver: new DictionaryPreCreateResolver($entries));

        $this->expectExceptionMessage('DICTIONARY_PRE_CREATE_REVIEW_REQUIRED');
        $service->createEntryWithSense('Côn', 'Không rõ nghĩa', [], 'entry-ambiguous-1');
    }

    public function test_precreate_resolution_allows_one_new_entry_and_records_resolution(): void
    {
        $entries = $this->entryRepository();
        $service = new DictionaryMutationService($this->conceptRepository(), entryRepository: $entries, preCreateResolver: new DictionaryPreCreateResolver($entries));

        $result = $service->createEntryWithSense('Kính rào', 'Nghĩa mới', [], 'entry-new-1');

        self::assertSame('CREATE_NEW', $result['resolution']['action']);
        self::assertNotSame('', $result['resolution']['fingerprint']);
        self::assertSame(1, $entries->createWrites);
    }

    public function test_create_new_rechecks_any_new_active_normalized_form_and_forces_replan(): void
    {
        $entries = new class implements DictionaryEntryRepository {
            public function findByForm(string $normalizedForm, array $context = []): array { return []; }
            public function findForConcept(string $conceptId): ?LexicalEntry { return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return []; }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
            public function createWithSenseResolved(LexicalEntry $entry, DictionaryConcept $sense, array $context, DictionaryPreCreateResolution $resolution): array { throw new \RuntimeException('DICTIONARY_PRE_CREATE_STALE'); }
            public function createWithSense(LexicalEntry $entry, DictionaryConcept $sense, array $context): array { throw new \LogicException('resolved write required'); }
        };
        $service = new DictionaryMutationService($this->conceptRepository(), entryRepository: $entries, preCreateResolver: new DictionaryPreCreateResolver($entries));

        $this->expectExceptionMessage('DICTIONARY_PRE_CREATE_STALE');
        $service->createEntryWithSense('Kính rào', 'Nghĩa', [], 'stale-create-race');
    }

    public function test_duplicate_form_and_sense_enrichment_reuse_without_repository_write(): void
    {
        $sense = new DictionaryConcept('sense-form', 'Côn', 'Nghĩa', DictionaryConcept::APPROVED, null, null, null, [], 1);
        $entry = new LexicalEntry('entry-form', 'Côn', 'côn', DictionaryConcept::APPROVED, 'vi-VN', [], 2, [$sense->conceptId]);
        $entries = $this->entryRepository([$entry], [$sense]);
        $service = new DictionaryMutationService($this->conceptRepository([$sense]), entryRepository: $entries, preCreateResolver: new DictionaryPreCreateResolver($entries));

        $form = $service->addFormToEntry('entry-form', 2, 'Côn', [], 'form-reuse-1');
        $mappedSense = $service->addSenseToEntry('entry-form', 2, 'sense-form', [], 'sense-reuse-1');

        self::assertTrue($form['duplicate']);
        self::assertTrue($mappedSense['duplicate']);
        self::assertSame(0, $entries->formWrites);
        self::assertSame(0, $entries->senseWrites);
    }

    public function test_same_key_replay_returns_recorded_result_before_live_resolution_changes(): void
    {
        $entries = $this->entryRepository();
        $receipts = [];
        $service = new DictionaryMutationService(
            $this->conceptRepository(),
            receiptReader: static function (string $key, string $fingerprint) use (&$receipts): ?array { return $receipts[$key] ?? null; },
            receiptWriter: static function (string $key, string $fingerprint, array $result) use (&$receipts): void { $receipts[$key] = ['fingerprint' => $fingerprint, 'result' => $result]; },
            entryRepository: $entries,
            preCreateResolver: new DictionaryPreCreateResolver($entries),
        );

        $first = $service->createEntryWithSense('Kính rào', 'Nghĩa mới', [], 'entry-replay-1');
        $entries->entries[] = $first['entry'];
        $replay = $service->createEntryWithSense('Kính rào', 'Nghĩa mới', [], 'entry-replay-1');

        self::assertSame($first['entry']->entryId, $replay['entry']->entryId);
        self::assertSame(1, $entries->createWrites);
    }

    private function conceptRepository(array $concepts = []): DictionaryConceptRepository
    {
        return new class($concepts) implements DictionaryConceptRepository {
            public function __construct(array $concepts) { $this->concepts = []; foreach ($concepts as $concept) if ($concept instanceof DictionaryConcept) $this->concepts[$concept->conceptId] = $concept; }
            public function findById(string $conceptId): ?DictionaryConcept { return $this->concepts[$conceptId] ?? null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return array_values($this->concepts); }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $this->concepts[$concept->conceptId] = $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $this->concepts[$concept->conceptId] = $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
    }

    private function formPreCreateRepository(): DictionaryEntryRepository
    {
        return new class implements DictionaryEntryRepository {
            public function findByForm(string $normalizedForm, array $context = []): array { return []; }
            public function findForConcept(string $conceptId): ?LexicalEntry { return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return []; }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
            public function findById(string $id): ?LexicalEntry { return new LexicalEntry($id, 'Côn', 'côn', DictionaryConcept::DRAFT, 'vi-VN', [], 1); }
        };
    }

    private function entryRepository(array $entries = [], array $senses = []): DictionaryEntryRepository
    {
        return new class($entries, $senses) implements DictionaryEntryRepository {
            public int $createWrites = 0;
            public int $formWrites = 0;
            public int $senseWrites = 0;
            public function __construct(public array $entries, private array $senses) {}
            public function findByForm(string $normalizedForm, array $context = []): array { return array_values(array_filter($this->entries, static fn (LexicalEntry $entry): bool => $entry->normalizedPreferredForm === $normalizedForm)); }
            public function findForConcept(string $conceptId): ?LexicalEntry { foreach ($this->entries as $entry) if (in_array($conceptId, $entry->senseIds, true)) return $entry; return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return array_values(array_filter($this->senses, static fn (DictionaryConcept $sense): bool => in_array($sense->conceptId, $entry->senseIds, true) && self::contextMatches($sense, $context))); }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
            public function findById(string $entryId): ?LexicalEntry { foreach ($this->entries as $entry) if ($entry->entryId === $entryId) return $entry; return null; }
            public function createWithSense(LexicalEntry $entry, DictionaryConcept $sense, array $context): array { $this->createWrites++; $this->entries[] = $entry; $this->senses[$sense->conceptId] = $sense; return ['entry' => $entry, 'sense' => $sense, 'forms' => []]; }
            public function addFormToEntry(string $entryId, int $expectedRevision, LexicalEntryForm $form): array { $this->formWrites++; return ['entry' => $this->findById($entryId), 'form' => $form]; }
            public function addSenseToEntry(string $entryId, int $expectedRevision, DictionaryConcept $sense, array $context = [], ?string $semanticType = null, ?string $semanticId = null, ?int $semanticRevision = null): array { $this->senseWrites++; return ['entry' => $this->findById($entryId), 'sense' => $sense]; }
            private static function contextMatches(DictionaryConcept $sense, array $context): bool { foreach ($context as $key => $value) if ($value !== null && $value !== '' && ($sense->context[$key] ?? null) !== $value) return false; return true; }
        };
    }
}
