<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryNaturalLanguageCaptureService;
use NHK\Core\Application\Dictionary\DictionaryPreCreateResolver;
use NHK\Core\Contracts\Dictionary\DictionaryEntryRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry, LexicalEntryForm};
use PHPUnit\Framework\TestCase;

final class DictionaryNaturalLanguageCaptureServiceTest extends TestCase
{
    public function test_natural_definition_for_unknown_form_becomes_create_plan(): void
    {
        $service = $this->service();

        $plan = $service->plan('Bổ sung vào từ điển "kính rào" nghĩa là một chi tiết bảo vệ.', 'capture-1', []);

        self::assertSame('CREATE', $plan['operation']);
        self::assertSame('CREATE_NEW', $plan['pre_create_resolution']['action']);
        self::assertSame('kính rào', $plan['preferred_form']);
        self::assertSame('một chi tiết bảo vệ', $plan['definition']);
    }

    public function test_alias_phrase_reuses_headword_and_adds_only_a_non_colliding_form(): void
    {
        $entry = $this->entry('entry-1', 'Côn', ['sense-1']);
        $service = $this->service([$entry], [$this->sense('sense-1', 'Côn')], ['côn' => [$entry]]);

        $plan = $service->plan('Côn còn gọi là côn máng.', 'capture-2', []);

        self::assertSame('ADD_FORM', $plan['operation']);
        self::assertSame('ADD_FORM_TO_ENTRY', $plan['pre_create_resolution']['action']);
        self::assertSame('entry-1', $plan['entry_id']);
        self::assertSame('côn máng', $plan['form']);
    }

    public function test_existing_definition_becomes_enrichment_and_factual_only_input_has_no_dictionary_plan(): void
    {
        $entry = $this->entry('entry-1', 'Côn', ['sense-1']);
        $service = $this->service([$entry], [$this->sense('sense-1', 'Côn')], ['côn' => [$entry]]);

        $enrichment = $service->plan('Bổ sung định nghĩa cho Côn: dụng cụ tạo góc.', 'capture-3', []);
        $fact = $service->plan('Bổ sung thêm dữ kiện cho Côn: sản xuất năm 2020.', 'capture-4', []);

        self::assertSame('ENRICH', $enrichment['operation']);
        self::assertSame('ENRICH_EXISTING', $enrichment['pre_create_resolution']['action']);
        self::assertSame('NOT_REQUESTED', $fact['status']);
        self::assertFalse($fact['dictionary_mutation']);
    }

    public function test_ambiguous_headword_fails_closed(): void
    {
        $first = $this->entry('entry-1', 'Côn', ['sense-1']);
        $second = $this->entry('entry-2', 'Côn', ['sense-2']);
        $service = $this->service([$first, $second], [$this->sense('sense-1', 'Côn'), $this->sense('sense-2', 'Côn')], ['côn' => [$first, $second]]);

        $plan = $service->plan('Bổ sung định nghĩa cho Côn: dụng cụ.', 'capture-5', []);

        self::assertSame('REVIEW_REQUIRED', $plan['status']);
        self::assertSame('REVIEW_REQUIRED', $plan['pre_create_resolution']['action']);
    }

    public function test_distinct_natural_meaning_gets_a_deterministic_new_sense_identity(): void
    {
        $entry = $this->entry('entry-1', 'Côn', ['sense-1']);
        $service = $this->service([$entry], [$this->sense('sense-1', 'Côn')], ['côn' => [$entry]]);

        $first = $service->plan('Côn có thêm nghĩa: một bộ phận của bút.', 'capture-6', []);
        $second = $service->plan('Côn có thêm nghĩa: một bộ phận của bút.', 'capture-7', []);

        self::assertSame('ADD_SENSE', $first['operation']);
        self::assertSame('ADD_SENSE_TO_ENTRY', $first['pre_create_resolution']['action']);
        self::assertSame($first['concept_id'], $second['concept_id']);
    }

    public function test_same_meaning_from_two_captures_reuses_one_existing_canonical_sense_identity(): void
    {
        $entry = $this->entry('entry-1', 'Côn', ['sense-1']);
        $service = $this->service([$entry], [$this->senseWithDefinition('sense-1', 'Côn', 'một bộ phận của bút.')], ['côn' => [$entry]]);

        $first = $service->plan('Côn có thêm nghĩa: một bộ phận của bút.', 'capture-a', []);
        $second = $service->plan('Côn có thêm nghĩa: một bộ phận của bút.', 'capture-b', []);

        self::assertSame('REUSE', $first['operation']);
        self::assertSame('REUSE', $second['operation']);
        self::assertSame($first['pre_create_resolution']['candidates'][0]['sense_id'], $second['pre_create_resolution']['candidates'][0]['sense_id']);
    }

    public function test_multiple_equivalent_existing_senses_fail_closed_for_new_sense(): void
    {
        $entry = $this->entry('entry-1', 'Côn', ['sense-1', 'sense-2']);
        $service = $this->service(
            [$entry],
            [$this->senseWithDefinition('sense-1', 'Côn', 'một bộ phận của bút.'), $this->senseWithDefinition('sense-2', 'Côn', 'một bộ phận của bút.')],
            ['côn' => [$entry]],
        );

        $plan = $service->plan('Côn có thêm nghĩa: một bộ phận của bút.', 'capture-c', []);

        self::assertSame('REVIEW_REQUIRED', $plan['status']);
        self::assertSame('MULTIPLE_EQUIVALENT_SENSES', $plan['pre_create_resolution']['diagnostics']['reason']);
    }

    public function test_mixed_lexical_and_factual_sentences_keep_fact_out_of_definition(): void
    {
        $service = $this->service();

        $plan = $service->plan('Bổ sung vào từ điển kính rào nghĩa là một chi tiết, sản xuất năm 2020.', 'capture-mixed', []);

        self::assertSame('CREATE', $plan['operation']);
        self::assertSame('một chi tiết', $plan['definition']);
        self::assertStringNotContainsString('2020', $plan['definition']);
        self::assertNotEmpty($plan['semantic_track']['assertions']);
    }

    public function test_ready_plan_applies_once_through_the_injected_canonical_mutation_boundary(): void
    {
        $calls = [];
        $service = new DictionaryNaturalLanguageCaptureService(
            new DictionaryPreCreateResolver($this->repository([], [], [])),
            static function (array $plan, string $key) use (&$calls): array {
                $calls[] = [$plan['operation'], $key];
                return ['entry_id' => 'entry-readback', 'revision' => 2];
            },
        );

        $plan = $service->plan('Bổ sung vào từ điển kính rào nghĩa là một chi tiết.', 'capture-7');
        $result = $service->apply($plan, 'capture-7:dictionary');

        self::assertSame('APPLIED', $result['status']);
        self::assertSame(['CREATE', 'capture-7:dictionary'], $calls[0]);
        self::assertSame(['entry_id' => 'entry-readback', 'revision' => 2], $result['canonical_readback']);
    }

    private function service(array $entries = [], array $senses = [], array $forms = []): DictionaryNaturalLanguageCaptureService
    {
        return new DictionaryNaturalLanguageCaptureService(
            new DictionaryPreCreateResolver($this->repository($entries, $senses, $forms)),
        );
    }

    private function sense(string $id, string $label): DictionaryConcept
    {
        return new DictionaryConcept($id, $label, 'Nghĩa cũ', DictionaryConcept::APPROVED, null, null, null, [], 2);
    }

    private function senseWithDefinition(string $id, string $label, string $definition): DictionaryConcept
    {
        return new DictionaryConcept($id, $label, $definition, DictionaryConcept::APPROVED, null, null, null, [], 2);
    }

    private function entry(string $id, string $label, array $senseIds): LexicalEntry
    {
        return new LexicalEntry($id, $label, mb_strtolower($label), DictionaryConcept::APPROVED, 'vi-VN', [], 3, $senseIds);
    }

    private function repository(array $entries, array $senses, array $forms): DictionaryEntryRepository
    {
        return new class($entries, $senses, $forms) implements DictionaryEntryRepository {
            public function __construct(private array $entries, private array $senses, private array $forms) {}
            public function findByForm(string $normalizedForm, array $context = []): array { return $this->forms[$normalizedForm] ?? []; }
            public function findById(string $entryId): ?LexicalEntry { foreach ($this->entries as $entry) if ($entry->entryId === $entryId) return $entry; return null; }
            public function findForConcept(string $conceptId): ?LexicalEntry { foreach ($this->entries as $entry) if (in_array($conceptId, $entry->senseIds, true)) return $entry; return null; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return array_values(array_filter($this->senses, static fn (DictionaryConcept $sense): bool => in_array($sense->conceptId, $entry->senseIds, true))); }
            public function addForm(LexicalEntryForm $form): LexicalEntryForm { return $form; }
        };
    }
}
