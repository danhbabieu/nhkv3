<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryPublicQuery;
use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};
use NHK\Core\Domain\Dictionary\LexicalEntry;
use NHK\Core\Infrastructure\Dictionary\WpdbDictionaryEntryRepository;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class DictionaryPublicQueryTest extends TestCase
{
    public function test_public_search_ranks_preferred_exact_before_alias_and_definition_matches(): void
    {
        $preferred = new DictionaryConcept('c1', 'Côn', 'Bộ phận hình nón.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'con']);
        $alias = new DictionaryConcept('c2', 'Bộ máy', 'Côn là cách gọi trong giới sưu tầm.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'bo-may']);
        $repo = $this->repository([$preferred, $alias], [
            'c1' => [new DictionaryLabel('c1', 'Côn', 'côn', DictionaryLabel::PREFERRED)],
            'c2' => [new DictionaryLabel('c2', 'Côn máy', 'côn máy', DictionaryLabel::COLLOQUIAL)],
        ]);

        $items = (new DictionaryPublicQuery($repo))->hub(500, 'côn')['items'];

        self::assertSame('Côn', $items[0]['title']);
        self::assertSame(['Côn'], array_column(array_slice($items, 0, 1), 'title'));
    }

    public function test_numeric_entry_search_resolves_preferred_and_all_forms(): void
    {
        $sense = new DictionaryConcept('400-sense', '400 ngày', 'Loại đồng hồ.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => '400-ngay']);
        $repo = $this->repository([$sense], ['400-sense' => []]);
        $entries = new class($sense) {
            public function __construct(private DictionaryConcept $sense) {}
            public function listEntries(int $limit = 500): array { return [new LexicalEntry('400-entry', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => '400-ngay'], 1, [$this->sense->conceptId])]; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return [
                new \NHK\Core\Domain\Dictionary\LexicalEntryForm($entry->entryId, '400 ngày', '400 ngày', 'PREFERRED'),
                new \NHK\Core\Domain\Dictionary\LexicalEntryForm($entry->entryId, '400-Day Clock', '400-day clock', 'ALTERNATE'),
                new \NHK\Core\Domain\Dictionary\LexicalEntryForm($entry->entryId, 'Jahresuhr/400', 'jahresuhr/400', 'ALTERNATE'),
                new \NHK\Core\Domain\Dictionary\LexicalEntryForm($entry->entryId, 'Anniversary clock', 'anniversary clock', 'TECHNICAL'),
            ]; }
        };
        $query = new DictionaryPublicQuery($repo, null, null, $entries);

        foreach (['400', '400 ngày', '400-Day Clock', 'Anniversary clock'] as $term) {
            $items = $query->hub(500, $term)['items'];
            self::assertCount(1, $items, $term);
            self::assertSame('400 ngày', $items[0]['title'], $term);
        }
    }

    public function test_detail_packet_exposes_structured_semantic_sections_without_graph_mutation(): void
    {
        $sense = new DictionaryConcept('sense-400', '400 ngày', 'Loại đồng hồ.', DictionaryConcept::APPROVED, 'classification', 'owner-400', '/loai-dong-ho/400-ngay/', ['public_slug' => '400-ngay']);
        $repo = $this->repository([$sense], ['sense-400' => []]);
        $entries = new class($sense) {
            public function __construct(private DictionaryConcept $sense) {}
            public function listEntries(int $limit = 500): array { return [new LexicalEntry('entry-400', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => '400-ngay'], 1, [$this->sense->conceptId])]; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
        };
        $projection = static fn (string $type, string $id): array => [
            'identity' => ['type' => $type, 'id' => $id, 'title' => 'Đồng hồ 400 ngày', 'url' => '/loai-dong-ho/400-ngay/'],
            'knowledge' => ['items' => [['id' => 'knowledge-1']]],
            'relation_sections' => ['brands' => [['title' => 'Junghans']], 'models' => [], 'specimens' => [], 'media' => [['title' => 'Ảnh']], 'videos' => [['title' => 'Video']], 'articles' => [['title' => 'Bài viết']]],
        ];
        $query = new DictionaryPublicQuery($repo, null, null, $entries, null, $projection);

        $item = $query->detail('400-ngay')['item'];

        self::assertSame('Đồng hồ 400 ngày', $item['canonical_owner']['title']);
        self::assertSame([['id' => 'knowledge-1']], $item['knowledge']['items']);
        self::assertSame('Junghans', $item['brands']['items'][0]['title']);
        self::assertSame('Ảnh', $item['media']['items'][0]['title']);
        self::assertSame([], $item['mentions']['groups']);
    }

    public function test_public_search_supports_visible_alias_and_hidden_lookup_without_exposing_hidden_label(): void
    {
        $concept = new DictionaryConcept('c1', 'Cylindre à picots', 'Bộ thoát.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'cylindre-a-picots']);
        $repo = $this->repository([$concept], [
            'c1' => [
                new DictionaryLabel('c1', 'Quả lô gai', 'quả lô gai', DictionaryLabel::ALTERNATE),
                new DictionaryLabel('c1', 'Pinned cylinder', 'pinned cylinder', DictionaryLabel::HIDDEN),
            ],
        ]);

        $visible = (new DictionaryPublicQuery($repo))->hub(500, 'Quả lô gai')['items'];
        $hidden = (new DictionaryPublicQuery($repo))->hub(500, 'Pinned cylinder')['items'];

        self::assertSame('Cylindre à picots', $visible[0]['title']);
        self::assertCount(1, $hidden);
        self::assertNotContains('Pinned cylinder', array_column($hidden[0]['labels'], 'label'));
    }

    public function test_hub_delegates_existing_owner_and_keeps_dedicated_dictionary_route_only_when_needed(): void
    {
        $owner = new DictionaryConcept('c1', 'Westminster', 'Bản nhạc được tra cứu.', DictionaryConcept::APPROVED, 'music', 'music-1', '/ban-nhac/westminster/', ['category' => 'music']);
        $local = new DictionaryConcept('c2', 'Vai bò', 'Tên gọi dân gian tại Việt Nam.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'vai-bo', 'term_type' => 'COLLOQUIAL']);
        $repo = $this->repository([$owner, $local], [
            'c1' => [new DictionaryLabel('c1', 'Westminster', 'westminster', DictionaryLabel::PREFERRED)],
            'c2' => [new DictionaryLabel('c2', 'Vai bò', 'vai bò', DictionaryLabel::PREFERRED), new DictionaryLabel('c2', 'đồng hồ vai bò', 'đồng hồ vai bò', DictionaryLabel::COLLOQUIAL)],
        ]);

        $packet = (new DictionaryPublicQuery($repo, static fn (string $id): ?array => $id === 'c2' ? ['url' => '/media/vai-bo.webp', 'alt' => 'Vai bò'] : null))->hub();
        $items = [];
        foreach ($packet['items'] as $item) $items[$item['title']] = $item;

        self::assertSame('/ban-nhac/westminster/', $items['Westminster']['url']);
        self::assertFalse($items['Westminster']['dedicated']);
        self::assertSame('/tu-dien/vai-bo/', $items['Vai bò']['url']);
        self::assertTrue($items['Vai bò']['dedicated']);
        self::assertSame('/media/vai-bo.webp', $items['Vai bò']['image']['url']);
    }

    public function test_detail_does_not_create_duplicate_page_for_owner_delegated_concept(): void
    {
        $owner = new DictionaryConcept('c1', 'Westminster', 'Bản nhạc được tra cứu.', DictionaryConcept::APPROVED, 'music', 'music-1', '/ban-nhac/westminster/', ['public_slug' => 'westminster']);
        $repo = $this->repository([$owner], ['c1' => []]);

        $result = (new DictionaryPublicQuery($repo))->detail('westminster');

        self::assertSame('REDIRECT', $result['status']);
        self::assertSame('/ban-nhac/westminster/', $result['destination_url']);
    }

    public function test_delegated_owner_uses_revalidated_current_url_instead_of_stale_snapshot(): void
    {
        $owner = new DictionaryConcept('c1', 'Westminster', 'Bản nhạc được tra cứu.', DictionaryConcept::APPROVED, 'music', 'music-1', '/ban-nhac/cu/', ['public_slug' => 'westminster']);
        $repo = $this->repository([$owner], ['c1' => [new DictionaryLabel('c1', 'Westminster', 'westminster', DictionaryLabel::PREFERRED)]]);
        $query = new DictionaryPublicQuery($repo, null, static fn (?string $type, ?string $id, ?string $url): ?string => '/ban-nhac/westminster/');

        $packet = $query->hub();

        if (count($packet['items']) !== 1) self::fail(json_encode($packet, JSON_UNESCAPED_UNICODE));
        self::assertSame('/ban-nhac/westminster/', $packet['items'][0]['url']);
        self::assertFalse($packet['items'][0]['indexable']);
    }

    public function test_entry_hub_and_detail_render_multiple_senses_without_collapsing_them(): void
    {
        $first = new DictionaryConcept('11111111-1111-7111-8111-111111111111', 'Côn máy', 'Nghĩa máy', DictionaryConcept::APPROVED, 'model', '22222222-2222-7222-8222-222222222222', '/dong-ho/may/', ['domain' => 'máy']);
        $second = new DictionaryConcept('33333333-3333-7333-8333-333333333333', 'Côn bút', 'Nghĩa bút', DictionaryConcept::APPROVED, null, null, null, ['domain' => 'bút']);
        $repo = $this->repository([$first, $second], ['11111111-1111-7111-8111-111111111111' => [], '33333333-3333-7333-8333-333333333333' => []]);
        $entries = new class($first, $second) {
            public function __construct(private DictionaryConcept $first, private DictionaryConcept $second) {}
            public function listEntries(int $limit = 500): array { return [new LexicalEntry('44444444-4444-7444-8444-444444444444', 'Côn', 'côn', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'con'], 1, [$this->first->conceptId, $this->second->conceptId])]; }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return [$this->first, $this->second]; }
        };
        $query = new DictionaryPublicQuery($repo, null, static fn (?string $type, ?string $id, ?string $url): ?string => $url, $entries);
        $packet = $query->hub();
        if (count($packet['items']) !== 1) self::fail(json_encode($packet, JSON_UNESCAPED_UNICODE));
        self::assertCount(2, $packet['items'][0]['senses']);
        self::assertSame('/tu-dien/con/', $packet['items'][0]['url']);
        self::assertSame('READY', $query->detail('con')['status']);
    }

    public function test_entry_detail_fails_closed_when_public_slug_matches_multiple_entries(): void
    {
        $first = new DictionaryConcept('c1', 'Côn máy', 'Nghĩa máy', DictionaryConcept::APPROVED);
        $second = new DictionaryConcept('c2', 'Côn bút', 'Nghĩa bút', DictionaryConcept::APPROVED);
        $repo = $this->repository([$first, $second], ['c1' => [], 'c2' => []]);
        $entries = new class($first, $second) {
            public function __construct(private DictionaryConcept $first, private DictionaryConcept $second) {}
            public function listEntries(int $limit = 500): array
            {
                return [
                    new LexicalEntry('e1', 'Côn máy', 'côn máy', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'con'], 1, [$this->first->conceptId]),
                    new LexicalEntry('e2', 'Côn bút', 'côn bút', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'con'], 1, [$this->second->conceptId]),
                ];
            }
            public function listSenses(LexicalEntry $entry, array $context = []): array
            {
                return [$entry->entryId === 'e1' ? $this->first : $this->second];
            }
        };

        $query = new DictionaryPublicQuery($repo, null, null, $entries);

        self::assertSame('AMBIGUOUS', $query->detail('con')['status']);
    }

    public function test_missing_entry_sense_schema_uses_concept_compatibility_without_calling_entry_repository(): void
    {
        $concept = new DictionaryConcept('c1', 'Vai bò', 'Tên gọi dân gian.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'vai-bo']);
        $repo = $this->repository([$concept], ['c1' => [new DictionaryLabel('c1', 'Vai bò', 'vai-bo', DictionaryLabel::PREFERRED)]]);
        $entries = new class {
            public function listEntries(int $limit = 500): array { throw new \LogicException('entry repository must not be queried'); }
        };

        $query = new DictionaryPublicQuery($repo, null, null, $entries, static fn (): bool => false);
        $packet = $query->hub();

        self::assertSame('AVAILABLE', $packet['status']);
        self::assertSame('Vai bò', $packet['items'][0]['title']);
        self::assertSame('/tu-dien/vai-bo/', $packet['items'][0]['url']);
        self::assertSame('READY', $query->detail('vai-bo')['status']);
    }

    public function test_entry_with_empty_destination_fields_is_public_standalone(): void
    {
        $concept = new DictionaryConcept('c-empty', 'Bộ nhớ cơ khí', 'Nghĩa độc lập.', DictionaryConcept::APPROVED, '', '', '', []);
        $repo = $this->repository([$concept], ['c-empty' => []]);
        $entries = new class($concept) {
            public function __construct(private DictionaryConcept $concept) {}
            public function listEntries(int $limit = 500): array
            {
                return [new LexicalEntry('e-empty', 'Bộ nhớ cơ khí', 'bộ nhớ cơ khí', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'bo-nho-co-khi'], 1, [$this->concept->conceptId])];
            }
            public function listSenses(LexicalEntry $entry, array $context = []): array { return [$this->concept]; }
        };

        $query = new DictionaryPublicQuery($repo, null, static fn (?string $type, ?string $id, ?string $url): ?string => null, $entries);

        self::assertSame(1, $query->hub()['count']);
        self::assertSame('READY', $query->detail('bo-nho-co-khi')['status']);
    }

    public function test_persisted_entry_hydration_includes_active_sense_ids(): void
    {
        if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
        $entryId = '11111111-1111-7111-8111-111111111111';
        $senseId = '22222222-2222-7222-8222-222222222222';
        $database = new class($entryId, $senseId) {
            public string $prefix = 'wp_';
            public function __construct(private string $entryId, private string $senseId) {}
            public function prepare(string $query, mixed ...$args): string { return $query; }
            public function get_row(string $query, mixed $output): array
            {
                return ['entry_uuid' => UuidCodec::toBinary($this->entryId), 'preferred_form' => 'Côn', 'normalized_preferred_form' => 'côn', 'status' => DictionaryConcept::APPROVED, 'locale' => 'vi-VN', 'context_json' => '{}', 'revision' => 1];
            }
            public function get_results(string $query, mixed $output): array { return [['concept_uuid' => UuidCodec::toBinary($this->senseId)]]; }
        };
        $concepts = $this->repository([new DictionaryConcept($senseId, 'Côn', 'Nghĩa', DictionaryConcept::APPROVED)], []);

        $entry = (new WpdbDictionaryEntryRepository($database, $concepts))->findDurableForConcept($senseId);

        self::assertInstanceOf(LexicalEntry::class, $entry);
        self::assertSame([$senseId], $entry->senseIds);
    }

    private function repository(array $concepts, array $labels): DictionaryConceptRepository
    {
        return new class($concepts, $labels) implements DictionaryConceptRepository {
            public function __construct(private array $concepts, private array $labels) {}
            public function findById(string $conceptId): ?DictionaryConcept { foreach ($this->concepts as $concept) if ($concept->conceptId === $conceptId) return $concept; return null; }
            public function findApprovedByNormalizedLabel(string $normalizedLabel, array $context = []): array { return []; }
            public function listApproved(int $limit = 500): array { return array_slice($this->concepts, 0, $limit); }
            public function listLabels(string $conceptId, bool $includeInactive = false): array { return $this->labels[$conceptId] ?? []; }
            public function createConcept(DictionaryConcept $concept): DictionaryConcept { return $concept; }
            public function updateConcept(DictionaryConcept $concept, int $expectedRevision): DictionaryConcept { return $concept; }
            public function addLabel(DictionaryLabel $label): DictionaryLabel { return $label; }
            public function saveLabel(DictionaryLabel $label, string $previousNormalizedLabel, int $expectedConceptRevision): DictionaryLabel { return $label; }
        };
    }
}
