<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary {
    function get_option(string $key, mixed $default = false): mixed { return $default; }
}

namespace NHK\Tests\Unit {

use NHK\Core\Application\Dictionary\{DictionaryMutationService, DictionaryPublicQuery, DictionaryResolver, DictionaryRuntime, DictionaryTermNormalizer};
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel, LexicalEntry, LexicalEntryForm};
use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Infrastructure\Dictionary\{WpdbDictionaryConceptRepository, WpdbDictionaryEntryRepository};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

final class DictionaryNewEntryPublicProjectionTest extends TestCase
{
    public function test_brand_new_approved_entry_round_trips_through_search_detail_resolve_and_replay(): void
    {
        $oldSense = new DictionaryConcept('old-sense', '400 ngày', 'Cũ', DictionaryConcept::APPROVED);
        $concepts = new class($oldSense) implements DictionaryConceptRepository {
            /** @var array<string,DictionaryConcept> */
            public array $items;
            public function __construct(DictionaryConcept $old) { $this->items = [$old->conceptId => $old]; }
            public function findById(string $conceptId): ?DictionaryConcept { return $this->items[$conceptId] ?? null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return array_values(array_filter($this->items, static fn (DictionaryConcept $item): bool => $item->approved())); }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $this->items[$concept->conceptId] = $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept
            {
                $current = $this->items[$concept->conceptId] ?? null;
                if (!$current instanceof DictionaryConcept || $current->revision !== $expectedRevision) throw new \RuntimeException('DICTIONARY_CONCEPT_REVISION_CONFLICT');
                return $this->items[$concept->conceptId] = new DictionaryConcept($concept->conceptId, $concept->preferredLabel, $concept->definition, $concept->status, $concept->destinationType, $concept->destinationId, $concept->destinationUrl, $concept->context, $expectedRevision + 1);
            }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
        $entries = new class($oldSense) {
            /** @var array<string,LexicalEntry> */
            public array $items;
            public int $writes = 0;
            public function __construct(DictionaryConcept $old) { $this->items = ['old-entry' => new LexicalEntry('old-entry', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => '400-ngay'], 1, [$old->conceptId])]; }
            public function createWithSense(LexicalEntry $entry, DictionaryConcept $sense, array $context): array { $this->writes++; return ['entry' => $this->items[$entry->entryId] = $entry, 'sense' => $sense, 'forms' => [new LexicalEntryForm($entry->entryId, $entry->preferredForm, $entry->normalizedPreferredForm, LexicalEntryForm::PREFERRED, $entry->locale)]]; }
            public function syncStatusForSense(string $senseId, string $status): ?LexicalEntry
            {
                foreach ($this->items as $id => $entry) if (in_array($senseId, $entry->senseIds, true)) return $this->items[$id] = new LexicalEntry($entry->entryId, $entry->preferredForm, $entry->normalizedPreferredForm, $status, $entry->locale, $entry->context, $entry->revision + 1, $entry->senseIds);
                return null;
            }
            public function listEntries(int $limit = 500): array { return array_values($this->items); }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return [new DictionaryConcept($entry->senseIds[0], $entry->preferredForm, 'Nghĩa', $entry->status)]; }
            public function listForms(LexicalEntry $entry): array { return [new LexicalEntryForm($entry->entryId, $entry->preferredForm, $entry->normalizedPreferredForm, LexicalEntryForm::PREFERRED, $entry->locale)]; }
            public function findByPublicSlug(string $slug): ?LexicalEntry { foreach ($this->items as $entry) if (($entry->context['public_slug'] ?? '') === $slug && $entry->status === DictionaryConcept::APPROVED) return $entry; return null; }
            public function findByForm(string $normalizedForm, array $context = []): array { return array_values(array_filter($this->items, static fn (LexicalEntry $entry): bool => $entry->status === DictionaryConcept::APPROVED && $entry->normalizedPreferredForm === $normalizedForm)); }
            public function publicSlugTaken(string $slug, ?string $entryId = null): bool { foreach ($this->items as $entry) if ($entry->entryId !== $entryId && ($entry->context['public_slug'] ?? '') === $slug) return true; return false; }
        };
        $receipts = [];
        $writer = new \NHK\Core\Application\Dictionary\DictionaryEntryPublicIdentityWriter(fn (string $slug, ?string $entryId = null): bool => $entries->publicSlugTaken($slug, $entryId));
        $service = new DictionaryMutationService(
            $concepts,
            receiptReader: static function (string $key, string $fingerprint) use (&$receipts): ?array { return $receipts[$key] ?? null; },
            receiptWriter: static function (string $key, string $fingerprint, array $result) use (&$receipts): void { $receipts[$key] = ['fingerprint' => $fingerprint, 'result' => $result]; },
            entryRepository: $entries,
            entryPublicIdentityWriter: $writer,
        );

        $created = $service->createEntryWithSense('Kính rào', 'Nghĩa', [], 'brand-new-entry-1');
        $concepts->createConcept($created['sense']);
        $approved = $service->setConceptStatus($created['sense']->conceptId, 1, DictionaryConcept::APPROVED, 'brand-new-approve-1');
        $query = new DictionaryPublicQuery($concepts, null, null, $entries);

        $searched = $query->hub(500, 'Kính rào');
        $detail = $query->detail('kinh-rao');
        $resolver = new DictionaryResolver(
            approvedLabelLookup: function (string $term, array $context) use ($entries): array {
                $entry = $entries->findByForm($term, $context)[0] ?? null;
                return $entry instanceof LexicalEntry ? [['concept_id' => $entry->senseIds[0], 'preferred_label' => $entry->preferredForm, 'destination_type' => 'dictionary', 'destination_id' => $entry->senseIds[0], 'destination_url' => '/tu-dien/' . $entry->context['public_slug'] . '/']] : [];
            },
            entityLookup: static fn (): array => [], knowledgeLookup: static fn (): array => [], articleLookup: static fn (): array => [], suppressionLookup: static fn (): bool => false,
            normalizer: new DictionaryTermNormalizer(),
        );
        $resolved = $resolver->resolve('Kính rào');
        $replay = $service->createEntryWithSense('Kính rào', 'Nghĩa', [], 'brand-new-entry-1');

        self::assertSame(DictionaryConcept::APPROVED, $approved['entry']->status);
        self::assertSame('kinh-rao', $approved['entry']->context['public_slug']);
        self::assertSame(1, $searched['count']);
        self::assertSame('/tu-dien/kinh-rao/', $searched['items'][0]['url']);
        self::assertSame('READY', $detail['status']);
        self::assertSame($created['entry']->entryId, $detail['item']['entry_id']);
        self::assertSame('/tu-dien/kinh-rao/', $resolved->destinationUrl);
        self::assertSame($created['entry']->entryId, $replay['entry']->entryId);
        self::assertSame(1, $entries->writes);
        self::assertSame('400-ngay', $entries->items['old-entry']->context['public_slug']);
    }

    public function test_profile_preview_resolves_the_new_entry_from_its_persisted_sense_projection(): void
    {
        $senseId = '01a10bd1-2331-7394-adce-5ded07943d63';
        $entryId = '01a10bd1-2331-76ec-adce-5ded07baaae4';
        $database = new class($senseId, $entryId) {
            public string $prefix = 'wp_';
            public function __construct(private string $senseId, private string $entryId) {}
            public function prepare(string $query, mixed ...$args): string
            {
                foreach ($args as $arg) $query = (string) preg_replace_callback('/%[sd]/', static fn (): string => (string) $arg, $query, 1);
                return $query;
            }
            public function get_var(string $query): mixed
            {
                if (str_contains($query, 'SHOW TABLES LIKE')) {
                    preg_match('/(wp_nhk_dictionary_[a-z_]+)/', $query, $match);
                    return $match[1] ?? null;
                }
                return 0;
            }
            public function get_row(string $query, mixed $output = null): ?array
            {
                if (str_contains($query, 'nhk_dictionary_concepts')) return [
                    'concept_uuid' => UuidCodec::toBinary($this->senseId), 'preferred_label' => 'Kính rào',
                    'definition_text' => 'Nghĩa', 'status' => DictionaryConcept::APPROVED,
                    'destination_type' => null, 'destination_id' => null, 'destination_url' => null,
                    'context_json' => '{}', 'revision' => 2,
                ];
                if (str_contains($query, 'nhk_dictionary_entries')) return [
                    'entry_uuid' => UuidCodec::toBinary($this->entryId), 'preferred_form' => 'Kính rào',
                    'normalized_preferred_form' => 'kính rào', 'status' => DictionaryConcept::APPROVED,
                    'locale' => 'vi-VN', 'context_json' => '{"public_slug":"kinh-rao"}', 'revision' => 2,
                ];
                return null;
            }
            public function get_results(string $query, mixed $output = null): array
            {
                if (str_contains($query, 'SELECT * FROM wp_nhk_dictionary_entries')) return [[
                    'entry_uuid' => UuidCodec::toBinary($this->entryId), 'preferred_form' => 'Kính rào',
                    'normalized_preferred_form' => 'kính rào', 'status' => DictionaryConcept::APPROVED,
                    'locale' => 'vi-VN', 'context_json' => '{"public_slug":"kinh-rao"}', 'revision' => 2,
                ]];
                if (str_contains($query, 'concept_uuid FROM wp_nhk_dictionary_entry_senses')) return [['concept_uuid' => UuidCodec::toBinary($this->senseId)]];
                if (str_contains($query, 'wp_nhk_dictionary_forms')) return [['form_text' => 'Kính rào', 'normalized_form' => 'kính rào', 'form_kind' => LexicalEntryForm::PREFERRED, 'locale' => 'vi-VN', 'context_json' => '{}', 'state' => 1]];
                return [];
            }
        };
        $concepts = new WpdbDictionaryConceptRepository($database);
        $entries = new WpdbDictionaryEntryRepository($database, $concepts);
        $publicQuery = new DictionaryPublicQuery($concepts, null, null, $entries, static fn (): bool => true);
        $runtime = (new \ReflectionClass(DictionaryRuntime::class))->newInstanceWithoutConstructor();
        foreach ([
            'database' => $database,
            'concepts' => $concepts,
            'entries' => $entries,
            'publicQuery' => $publicQuery,
        ] as $property => $value) (new \ReflectionProperty($runtime, $property))->setValue($runtime, $value);

        $profile = $runtime->profile($senseId);

        self::assertSame('READY', $profile['public_preview']['status']);
        self::assertSame('/tu-dien/kinh-rao/', $profile['public_preview']['item']['url']);
    }
}
}
