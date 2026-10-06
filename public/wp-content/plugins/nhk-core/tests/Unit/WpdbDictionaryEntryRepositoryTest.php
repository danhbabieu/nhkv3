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

    public function test_guard_uses_context_hash_and_does_not_make_identical_forms_globally_unique(): void
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

        self::assertTrue(array_filter($database->queries, static fn (string $query): bool => str_contains($query, 'context_hash')) !== []);
        self::assertFalse(array_filter($database->queries, static fn (string $query): bool => str_contains($query, 'UNIQUE') || str_contains($query, 'normalized_form=' . 'normalized_form')) !== []);
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
