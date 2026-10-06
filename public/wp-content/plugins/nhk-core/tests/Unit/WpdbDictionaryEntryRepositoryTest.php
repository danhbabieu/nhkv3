<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel, DictionaryPreCreateResolution, LexicalEntry, LexicalEntryForm};
use NHK\Core\Infrastructure\Dictionary\WpdbDictionaryEntryRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class WpdbDictionaryEntryRepositoryTest extends TestCase
{
    public function test_resolved_create_fails_closed_when_same_form_and_context_appears_during_transaction(): void
    {
        $database = new class {
            public string $prefix = 'wp_';
            public int $insertQueries = 0;
            public function prepare(string $query, mixed ...$args): string { return $query; }
            public function get_var(string $query): mixed { return null; }
            public function get_row(string $query, mixed $output = null): mixed { return null; }
            public function get_results(string $query, mixed $output = null): array
            {
                return str_contains($query, 'normalized_form=%s') ? [['id' => 1]] : [];
            }
            public function query(string $query): int|false { if (str_starts_with($query, 'INSERT')) $this->insertQueries++; return 1; }
        };
        $repo = new WpdbDictionaryEntryRepository($database, $this->conceptRepository());
        $entry = new LexicalEntry('22222222-2222-7222-8222-222222222222', 'Côn', 'côn', DictionaryConcept::DRAFT, 'vi-VN', ['domain' => 'clock'], 1);
        $sense = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'Côn', 'Nghĩa', DictionaryConcept::DRAFT, null, null, null, ['domain' => 'clock'], 1);
        $resolution = DictionaryPreCreateResolution::fromDecision(DictionaryPreCreateResolution::CREATE_NEW, 'côn', ['domain' => 'clock'], [], [], ['reason' => 'NO_APPLICABLE_CANDIDATE']);

        $this->expectExceptionMessage('DICTIONARY_PRE_CREATE_STALE');
        $repo->createWithSenseResolved($entry, $sense, ['domain' => 'clock'], $resolution);
        self::assertSame(0, $database->insertQueries);
    }

    public function test_guard_rechecks_any_active_normalized_collision_without_global_unique_constraint(): void
    {
        $database = new class {
            public string $prefix = 'wp_';
            public array $queries = [];
            public function prepare(string $query, mixed ...$args): string { $this->queries[] = $query; return $query; }
            public function get_var(string $query): mixed { return null; }
            public function get_results(string $query, mixed $output = null): array { return []; }
        };
        $repo = new WpdbDictionaryEntryRepository($database, $this->conceptRepository());
        $resolution = DictionaryPreCreateResolution::fromDecision(DictionaryPreCreateResolution::CREATE_NEW, 'côn', ['domain' => 'pen'], [], [], ['reason' => 'NO_APPLICABLE_CANDIDATE']);

        $repo->assertPreCreateStillValid($resolution, ['domain' => 'pen']);

        self::assertTrue(array_filter($database->queries, static fn (string $query): bool => str_contains($query, 'normalized_form=%s') && str_contains($query, 'FOR UPDATE')) !== []);
        self::assertFalse(array_filter($database->queries, static fn (string $query): bool => str_contains($query, 'UNIQUE') || str_contains($query, 'normalized_form=' . 'normalized_form')) !== []);
    }

    public function test_pre_create_lookup_includes_draft_and_retired_wpdb_rows(): void
    {
        $database = new class {
            public string $prefix = 'wp_';
            public array $queries = [];
            public function prepare(string $query, mixed ...$args): string { $this->queries[] = $query; return $query; }
            public function get_results(string $query, mixed $output = null): array { return []; }
        };
        $repo = new WpdbDictionaryEntryRepository($database, $this->conceptRepository());

        self::assertSame([], $repo->findPreCreateCandidates('côn hoa thị'));
        $query = implode("\n", $database->queries);
        self::assertStringContainsString('f.normalized_form=%s', $query);
        self::assertStringNotContainsString('e.status<>', $query);
    }

    public function test_add_form_rechecks_cross_entry_collision_inside_transaction(): void
    {
        $entryId = '22222222-2222-7222-8222-222222222222';
        $database = $this->raceDatabase($entryId, 'form');
        $repo = new WpdbDictionaryEntryRepository($database, $this->conceptRepository());
        $form = new LexicalEntryForm($entryId, 'Côn hoa thị', 'côn hoa thị', LexicalEntryForm::ALTERNATE, 'vi-VN', []);

        $this->expectExceptionMessage('DICTIONARY_PRE_CREATE_STALE');
        $repo->addFormToEntry($entryId, 1, $form);
    }

    public function test_add_sense_rechecks_existing_mapping_inside_transaction(): void
    {
        $entryId = '22222222-2222-7222-8222-222222222222';
        $sense = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'Côn', 'Nghĩa', DictionaryConcept::DRAFT);
        $database = $this->raceDatabase($entryId, 'sense');
        $repo = new WpdbDictionaryEntryRepository($database, $this->conceptRepository());

        $this->expectExceptionMessage('DICTIONARY_PRE_CREATE_STALE');
        $repo->addSenseToEntry($entryId, 1, $sense);
    }

    private function raceDatabase(string $entryId, string $race): object
    {
        return new class($entryId, $race) {
            public string $prefix = 'wp_';
            public function __construct(private string $entryId, private string $race) {}
            public function prepare(string $query, mixed ...$args): string { return $query; }
            public function get_row(string $query, mixed $output = null): ?array
            {
                return ['entry_uuid' => UuidCodec::toBinary($this->entryId), 'preferred_form' => 'Côn', 'normalized_preferred_form' => 'côn', 'status' => DictionaryConcept::DRAFT, 'locale' => 'vi-VN', 'context_json' => '{}', 'revision' => 1];
            }
            public function get_results(string $query, mixed $output = null): array { return str_contains($query, 'concept_uuid FROM') ? [] : []; }
            public function get_var(string $query): mixed
            {
                if ($this->race === 'form' && str_contains($query, 'entry_uuid<>')) return 'other-form';
                if ($this->race === 'sense' && str_contains($query, 'concept_uuid=%s AND state=1') && !str_contains($query, 'entry_uuid=%s AND concept_uuid=%s')) return 'existing-mapping';
                return null;
            }
            public function query(string $query): int|false { return 1; }
        };
    }

    private function conceptRepository(): DictionaryConceptRepository
    {
        return new class implements DictionaryConceptRepository {
            public function findById(string $conceptId): ?DictionaryConcept { return null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return []; }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
    }
}
