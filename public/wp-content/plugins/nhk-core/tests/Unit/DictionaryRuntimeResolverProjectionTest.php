<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\{DictionaryResolver, DictionaryRuntime, DictionaryTermNormalizer};
use NHK\Core\Domain\Authority\EntityTypeRegistry;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry};
use NHK\Core\Infrastructure\Dictionary\{WpdbDictionaryConceptRepository, WpdbDictionaryEntryRepository};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

final class DictionaryRuntimeResolverProjectionTest extends TestCase
{
    public function test_dictionary_resolve_projects_preferred_and_alternate_forms_to_the_persisted_entry_route(): void
    {
        $sense = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'Kính rào', 'Nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('22222222-2222-7222-8222-222222222222', 'Kính rào', 'kính rào', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'kinh-rao'], 1, [$sense->conceptId]);
        $runtime = $this->runtime($this->database($entry, $sense));

        $resolver = new DictionaryResolver(
            approvedLabelLookup: fn (string $term, array $context): array => $this->rows($runtime, $term, $context),
            entityLookup: static fn (): array => [],
            knowledgeLookup: static fn (): array => [],
            articleLookup: static fn (): array => [],
            suppressionLookup: static fn (): bool => false,
            normalizer: new DictionaryTermNormalizer(),
        );

        $preferred = $resolver->resolve('Kính rào');
        $alternate = $resolver->resolve('Kính cửa');

        self::assertSame('dictionary', $preferred->destinationType);
        self::assertSame($entry->entryId, $preferred->destinationId);
        self::assertSame('/tu-dien/kinh-rao/', $preferred->destinationUrl);
        self::assertSame($entry->entryId, $alternate->destinationId);
        self::assertSame('/tu-dien/kinh-rao/', $alternate->destinationUrl);
    }

    public function test_runtime_does_not_fall_back_to_concept_projection_when_public_slug_is_missing(): void
    {
        $sense = new DictionaryConcept('33333333-3333-7333-8333-333333333333', 'Không có slug', 'Nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('44444444-4444-7444-8444-444444444444', 'Không có slug', 'không có slug', DictionaryConcept::APPROVED, 'vi-VN', [], 1, [$sense->conceptId]);
        $database = $this->database($entry, $sense);
        $runtime = $this->runtime($database);

        self::assertSame([], $this->rows($runtime, 'không có slug', []));
        self::assertSame(0, $database->fallbackReads);
    }

    public function test_runtime_does_not_project_a_retired_entry_publicly(): void
    {
        $sense = new DictionaryConcept('55555555-5555-7555-8555-555555555555', 'Entry nghỉ', 'Nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('66666666-6666-7666-8666-666666666666', 'Entry nghỉ', 'entry nghỉ', DictionaryConcept::RETIRED, 'vi-VN', ['public_slug' => 'entry-nghi'], 1, [$sense->conceptId]);
        $database = $this->database($entry, $sense);
        $runtime = $this->runtime($database);

        self::assertSame([], $this->rows($runtime, 'entry nghỉ', []));
        self::assertSame(0, $database->fallbackReads);
    }

    private function rows(DictionaryRuntime $runtime, string $term, array $context): array
    {
        $method = new \ReflectionMethod($runtime, 'approvedLabelRows');
        return $method->invoke($runtime, (new DictionaryTermNormalizer())->normalize($term), $context);
    }

    private function runtime(object $database): DictionaryRuntime
    {
        $concepts = new WpdbDictionaryConceptRepository($database);
        $entries = new WpdbDictionaryEntryRepository($database, $concepts);
        $runtime = (new \ReflectionClass(DictionaryRuntime::class))->newInstanceWithoutConstructor();
        foreach (['database' => $database, 'entries' => $entries, 'concepts' => $concepts, 'normalizer' => new DictionaryTermNormalizer(), 'types' => new EntityTypeRegistry()] as $property => $value) {
            (new \ReflectionProperty($runtime, $property))->setValue($runtime, $value);
        }
        return $runtime;
    }

    private function database(LexicalEntry $entry, DictionaryConcept $sense): object
    {
        return new class($entry, $sense) {
            public string $prefix = 'wp_';
            public int $fallbackReads = 0;
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function prepare(string $query, mixed ...$args): string
            {
                foreach ($args as $arg) $query = (string) preg_replace('/%s/', (string) $arg, $query, 1);
                return $query;
            }
            public function get_var(string $query): mixed
            {
                if (preg_match('/SHOW TABLES LIKE\s+([^\s]+)/', $query, $match)) return trim($match[1], "'\"");
                return null;
            }
            public function get_results(string $query, mixed $output = null): array
            {
                if (str_contains($query, 'SELECT DISTINCT e.* FROM wp_nhk_dictionary_entries')) return [$this->entryRow()];
                if (str_contains($query, 'concept_uuid FROM wp_nhk_dictionary_entry_senses')) return [['concept_uuid' => UuidCodec::toBinary($this->sense->conceptId)]];
                if (str_contains($query, 'FROM wp_nhk_dictionary_concepts')) $this->fallbackReads++;
                return [];
            }
            public function get_row(string $query, mixed $output = null): ?array
            {
                if (str_contains($query, 'nhk_dictionary_concepts')) return $this->conceptRow();
                if (str_contains($query, 'SELECT e.* FROM wp_nhk_dictionary_entries')) return $this->entryRow();
                return null;
            }
            private function entryRow(): array { return ['entry_uuid' => UuidCodec::toBinary($this->entry->entryId), 'preferred_form' => $this->entry->preferredForm, 'normalized_preferred_form' => $this->entry->normalizedPreferredForm, 'status' => $this->entry->status, 'locale' => $this->entry->locale, 'context_json' => json_encode($this->entry->context), 'revision' => $this->entry->revision]; }
            private function conceptRow(): array { return ['concept_uuid' => UuidCodec::toBinary($this->sense->conceptId), 'preferred_label' => $this->sense->preferredLabel, 'definition_text' => $this->sense->definition, 'status' => $this->sense->status, 'destination_type' => $this->sense->destinationType, 'destination_id' => $this->sense->destinationId, 'destination_url' => $this->sense->destinationUrl, 'context_json' => json_encode($this->sense->context), 'revision' => $this->sense->revision]; }
        };
    }
}
