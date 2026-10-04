<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryDetailQuery;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry};
use PHPUnit\Framework\TestCase;

final class DictionaryDetailQueryTest extends TestCase
{
    public function test_detail_keeps_each_sense_independent_and_reuses_owner_dossier(): void
    {
        $first = new DictionaryConcept('s1', 'Côn', 'Nghĩa máy', DictionaryConcept::APPROVED, 'classification', 'owner-1');
        $second = new DictionaryConcept('s2', 'Côn', 'Nghĩa bút', DictionaryConcept::APPROVED, 'classification', 'owner-1');
        $entry = new LexicalEntry('entry-1', 'Côn', 'côn', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'con'], 1, ['s1', 's2']);
        $entries = new class($entry, $first, $second) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $first, private DictionaryConcept $second) {}
            public function findByPublicSlug(string $slug): LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry): array { return [$this->first, $this->second]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'type' => 'classification', 'id' => 'owner-1', 'source' => 'MAPPING']; }
        };
        $concepts = new class {
            public function listLabels(string $id): array { return []; }
        };
        $calls = 0;
        $query = new DictionaryDetailQuery($concepts, $entries, null, function (string $type, string $id) use (&$calls): array {
            $calls++;
            return ['identity' => ['type' => $type, 'id' => $id, 'title' => 'Chủ thể'], 'knowledge' => ['items' => [['id' => 'k1']]], 'relation_sections' => []];
        });

        $result = $query->detail('con');
        self::assertSame('READY', $result['status']);
        self::assertCount(2, $result['item']['senses']);
        self::assertSame('Nghĩa máy', $result['item']['senses'][0]['description']);
        self::assertSame('Nghĩa bút', $result['item']['senses'][1]['description']);
        self::assertSame([], $result['item']['senses'][0]['semantic_relations']['items']);
        self::assertSame(1, $calls);
        self::assertNull($result['item']['canonical_owner'] ?? null);
    }

    public function test_mapping_invalid_does_not_silently_fallback_to_concept_destination(): void
    {
        $sense = new DictionaryConcept('s1', '400 ngày', 'Định nghĩa', DictionaryConcept::APPROVED, 'classification', 'legacy-owner');
        $entry = new LexicalEntry('entry-1', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => '400-ngay'], 1, ['s1']);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByPublicSlug(string $slug): LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'INVALID', 'source' => 'MAPPING', 'type' => 'classification', 'id' => null]; }
        };
        $query = new DictionaryDetailQuery(new class { public function listLabels(string $id): array { return []; } }, $entries);
        $sensePacket = $query->detail('400-ngay')['item']['senses'][0];
        self::assertSame('INVALID', $sensePacket['semantic_reference']['status']);
        self::assertNull($sensePacket['canonical_owner']);
    }

    public function test_detail_exposes_durable_array_forms_without_running_enrichment_audit(): void
    {
        $sense = new DictionaryConcept('sense-forms', '400 ngày', 'Định nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('entry-forms', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => '400-ngay'], 1, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByPublicSlug(string $slug): LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return [['form' => 'Jahresuhr/400', 'kind' => 'ALTERNATE', 'locale' => 'de-DE']]; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'ABSENT']; }
            public function enrichmentAudit(): never { throw new \LogicException('public detail must not audit enrichment'); }
        };

        $result = (new DictionaryDetailQuery(new class { public function listLabels(string $id): array { return []; } }, $entries))->detail('400-ngay');

        self::assertSame('Jahresuhr/400', $result['item']['forms'][0]['form']);
        self::assertSame('de-DE', $result['item']['forms'][0]['locale']);
    }

    public function test_detail_resolves_the_same_fallback_slug_emitted_by_the_hub(): void
    {
        $sense = new DictionaryConcept('sense-fallback', '400 ngày', 'Định nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('entry-fallback', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', [], 1, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByPublicSlug(string $slug): ?LexicalEntry { return null; }
            public function listEntries(int $limit = 2000): array { return [$this->entry]; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'ABSENT']; }
        };

        $result = (new DictionaryDetailQuery(new class { public function listLabels(string $id): array { return []; } }, $entries))->detail('400-ng-ay');

        self::assertSame('READY', $result['status']);
        self::assertSame('400 ngày', $result['item']['title']);
        self::assertSame('/tu-dien/400-ng-ay/', $result['item']['url']);
    }

    public function test_detail_fails_closed_when_fallback_slug_is_shared_by_two_entries(): void
    {
        $firstSense = new DictionaryConcept('sense-first', 'Côn máy', 'Máy', DictionaryConcept::APPROVED);
        $secondSense = new DictionaryConcept('sense-second', 'Côn bút', 'Bút', DictionaryConcept::APPROVED);
        $first = new LexicalEntry('entry-first', 'Côn máy', 'côn máy', DictionaryConcept::APPROVED, 'vi-VN', [], 1, [$firstSense->conceptId]);
        $second = new LexicalEntry('entry-second', 'Côn máy', 'côn máy', DictionaryConcept::APPROVED, 'vi-VN', [], 1, [$secondSense->conceptId]);
        $entries = new class($first, $second, $firstSense, $secondSense) {
            public function __construct(private LexicalEntry $first, private LexicalEntry $second, private DictionaryConcept $firstSense, private DictionaryConcept $secondSense) {}
            public function findByPublicSlug(string $slug): ?LexicalEntry { return null; }
            public function listEntries(int $limit = 2000): array { return [$this->first, $this->second]; }
            public function listSenses(LexicalEntry $entry): array { return [$entry->entryId === 'entry-first' ? $this->firstSense : $this->secondSense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
        };

        $result = (new DictionaryDetailQuery(new class { public function listLabels(string $id): array { return []; } }, $entries))->detail('c-on-m-ay');

        self::assertSame('AMBIGUOUS', $result['status']);
    }

    public function test_detail_adapts_canonical_facets_and_primary_gallery_media_without_relation_shortcuts(): void
    {
        $sense = new DictionaryConcept('sense-400', '400 ngày', 'Định nghĩa độc lập.', DictionaryConcept::APPROVED, 'classification', 'owner-400');
        $entry = new LexicalEntry('entry-400', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => '400-ngay'], 1, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByPublicSlug(string $slug): LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'type' => 'classification', 'id' => 'owner-400', 'source' => 'MAPPING']; }
        };
        $projection = static fn (string $type, string $id): array => [
            'identity' => ['type' => $type, 'title' => 'Đồng hồ 400 ngày', 'url' => '/loai-dong-ho/400-ngay/'],
            'knowledge' => ['facets' => ['movement' => [['id' => 'k1']], 'material' => [['id' => 'k2']], 'origin' => [['id' => 'k3']], 'sound' => [['id' => 'k4']], 'case' => [['id' => 'k5']], 'extra' => [['id' => 'k6']], 'overflow' => [['id' => 'k7']]]],
            'primary_media' => ['id' => 'media-hero', 'url' => '/anh/hero.webp'],
            'media_gallery' => [['id' => 'media-hero', 'url' => '/anh/hero.webp'], ['id' => 'media-2', 'url' => '/anh/2.webp']],
            'relation_sections' => ['media' => [['id' => 'wrong-media']], 'videos' => [['id' => 'video-1', 'url' => '/video/v/']], 'wp_posts' => [['id' => 'article-1', 'url' => '/bai-viet/a/']], 'brands' => [['title' => 'Junghans']]],
        ];

        $item = (new DictionaryDetailQuery(new class { public function listLabels(string $id): array { return []; } }, $entries, null, $projection))->detail('400-ngay')['item']['senses'][0];

        self::assertCount(6, $item['knowledge']['items']);
        self::assertTrue($item['knowledge']['has_more']);
        self::assertSame('media-hero', $item['media']['items'][0]['id']);
        self::assertSame('video-1', $item['videos']['items'][0]['id']);
        self::assertSame('article-1', $item['articles']['items'][0]['id']);
    }

    public function test_owner_backed_rich_lexical_entry_is_noindex_but_remains_sitemap_excluded(): void
    {
        $sense = new DictionaryConcept('sense-seo', '400 ngày', 'Định nghĩa giàu nội dung.', DictionaryConcept::APPROVED, 'classification', 'owner-400');
        $entry = new LexicalEntry('entry-seo', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => '400-ngay'], 1, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByPublicSlug(string $slug): LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'type' => 'classification', 'id' => 'owner-400', 'source' => 'MAPPING']; }
        };

        $result = (new DictionaryDetailQuery(new class { public function listLabels(string $id): array { return []; } }, $entries, null, static fn (): array => ['identity' => ['title' => 'Owner'], 'knowledge' => ['facets' => []], 'relation_sections' => []]))->detail('400-ngay');

        self::assertSame('NOINDEX', $result['seo']['state']);
        self::assertFalse($result['seo']['sitemap']);
        self::assertSame('noindex,follow', $result['seo']['robots']);
    }
}
