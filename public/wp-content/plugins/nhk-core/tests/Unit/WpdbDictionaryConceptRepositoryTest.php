<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

use NHK\Core\Application\Dictionary\DictionaryResolver;
use NHK\Core\Domain\Dictionary\DictionaryResolution;
use NHK\Core\Infrastructure\Dictionary\WpdbDictionaryConceptRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class WpdbDictionaryConceptRepositoryTest extends TestCase
{
    public function test_same_concept_duplicate_labels_collapse_after_applicability_filtering(): void
    {
        $concept = '11111111-1111-4111-8111-111111111111';
        $repo = new WpdbDictionaryConceptRepository($this->database([
            $this->row($concept, 1, 'en', []),
            $this->row($concept, 2, 'vi', []),
        ]));
        $resolver = $this->resolver($repo);

        $result = $resolver->resolve('Clock', ['lexical_locale' => 'vi']);

        self::assertSame(DictionaryResolution::RESOLVED, $result->status);
        self::assertSame($concept, $result->conceptId);
    }

    public function test_same_concept_applicable_metadata_variants_are_not_ambiguous(): void
    {
        $concept = '22222222-2222-4222-8222-222222222222';
        $repo = new WpdbDictionaryConceptRepository($this->database([
            $this->row($concept, 1, 'vi', ['domain' => 'clock']),
            $this->row($concept, 2, 'vi', ['domain' => 'clock', 'review' => 'curator-b']),
        ]));

        $result = $this->resolver($repo)->resolve('Clock', ['lexical_locale' => 'vi', 'domain' => 'clock']);

        self::assertSame(DictionaryResolution::RESOLVED, $result->status);
        self::assertSame($concept, $result->conceptId);
    }

    public function test_distinct_applicable_concepts_remain_ambiguous(): void
    {
        $repo = new WpdbDictionaryConceptRepository($this->database([
            $this->row('33333333-3333-4333-8333-333333333333', 1, 'vi', []),
            $this->row('44444444-4444-4444-8444-444444444444', 2, 'vi', []),
        ]));

        self::assertSame(DictionaryResolution::AMBIGUOUS, $this->resolver($repo)->resolve('Clock', ['lexical_locale' => 'vi'])->status);
    }

    public function test_applicable_row_resolves_regardless_of_non_applicable_row_order(): void
    {
        $concept = '55555555-5555-4555-8555-555555555555';
        $repo = new WpdbDictionaryConceptRepository($this->database([
            $this->row($concept, 1, 'vi', ['domain' => 'clock']),
            $this->row($concept, 2, 'en', ['domain' => 'music']),
        ]));

        $result = $this->resolver($repo)->resolve('Clock', ['lexical_locale' => 'vi', 'domain' => 'clock']);

        self::assertSame(DictionaryResolution::RESOLVED, $result->status);
        self::assertSame($concept, $result->conceptId);
    }

    private function resolver(WpdbDictionaryConceptRepository $repo): DictionaryResolver
    {
        return new DictionaryResolver(
            static fn (string $term, array $context): array => $repo->findApprovedByNormalizedLabel($term, $context),
            static fn (): array => [], static fn (): array => [], static fn (): array => [], static fn (): bool => false,
        );
    }

    private function database(array $rows): object
    {
        return new class($rows) {
            public string $prefix = 'wp_';
            public function __construct(private array $rows) {}
            public function prepare(string $query, mixed ...$args): string { return $query; }
            public function get_results(string $query, mixed $output = null): array { return $this->rows; }
        };
    }

    private function row(string $concept, int $id, string $locale, array $context): array
    {
        return [
            'id' => $id,
            'concept_uuid' => UuidCodec::toBinary($concept),
            'preferred_label' => 'Clock',
            'definition_text' => 'A clock.',
            'status' => 'APPROVED',
            'destination_type' => 'dictionary',
            'destination_id' => $concept,
            'destination_url' => '/tu-dien/clock/',
            'context_json' => '{}',
            'revision' => 1,
            'label_text' => 'Clock',
            'label_kind' => 'PREFERRED',
            'locale' => $locale,
            'label_context_json' => json_encode($context),
        ];
    }
}
