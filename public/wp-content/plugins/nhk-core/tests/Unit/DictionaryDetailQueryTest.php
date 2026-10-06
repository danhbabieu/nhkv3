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

    public function test_mapping_with_unresolvable_owner_is_invalid_on_public_detail(): void
    {
        $sense = new DictionaryConcept('s1', '400 ngày', 'Định nghĩa', DictionaryConcept::APPROVED);
        $entry = new LexicalEntry('entry-1', '400 ngày', '400 ngày', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => '400-ngay'], 1, ['s1']);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByPublicSlug(string $slug): LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'source' => 'MAPPING', 'type' => 'classification', 'id' => 'missing-owner']; }
        };
        $query = new DictionaryDetailQuery(new class { public function listLabels(string $id): array { return []; } }, $entries, static fn (): ?string => null);

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

    public function test_detail_does_not_derive_a_route_from_preferred_form_without_persisted_identity(): void
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

        self::assertSame('NOT_FOUND', $result['status']);
    }

    public function test_detail_does_not_resolve_shared_preferred_form_without_persisted_identity(): void
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

        self::assertSame('NOT_FOUND', $result['status']);
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
            'relation_sections' => ['technical' => [['title' => 'Dùng chung với chuông', 'url' => '/tri-thuc/chuong/','predicate' => 'supports']], 'media' => [['id' => 'wrong-media']], 'videos' => [['id' => 'video-1', 'url' => '/video/v/']], 'wp_posts' => [['id' => 'article-1', 'url' => '/bai-viet/a/']], 'brands' => [['title' => 'Junghans']]],
        ];

        $item = (new DictionaryDetailQuery(new class { public function listLabels(string $id): array { return []; } }, $entries, null, $projection))->detail('400-ngay')['item']['senses'][0];

        self::assertCount(6, $item['knowledge']['items']);
        self::assertTrue($item['knowledge']['has_more']);
        self::assertSame('media-hero', $item['media']['items'][0]['id']);
        self::assertSame('video-1', $item['videos']['items'][0]['id']);
        self::assertSame('article-1', $item['articles']['items'][0]['id']);
        self::assertSame([['title' => 'Dùng chung với chuông', 'url' => '/tri-thuc/chuong/']], $item['semantic_relations']['items']);
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

    public function test_semantic_article_mention_is_suppressed_when_article_already_has_a_semantic_section(): void
    {
        $sense = new DictionaryConcept('sense-dedupe', 'Côn', 'Định nghĩa.', DictionaryConcept::APPROVED, 'model', 'owner-1');
        $entry = new LexicalEntry('entry-dedupe', 'Côn', 'côn', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'con'], 1, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByPublicSlug(string $slug): LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'type' => 'model', 'id' => 'owner-1', 'source' => 'MAPPING']; }
        };

        $result = (new DictionaryDetailQuery(
            new class { public function listLabels(string $id): array { return []; } },
            $entries,
            null,
            static fn (): array => [
                'identity' => ['type' => 'model', 'id' => 'owner-1', 'title' => 'Mẫu'],
                'knowledge' => ['items' => []],
                'relation_sections' => ['articles' => [['id' => 'article-1', 'title' => 'Bài viết', 'url' => '/bai-viet/con/']]],
            ],
            static fn (): array => ['status' => 'AVAILABLE_WITH_ITEMS', 'groups' => ['ARTICLE' => [['id' => 'article-1', 'title' => 'Bài viết', 'url' => '/bai-viet/con/']]]],
        ))->detail('con');

        self::assertSame('article-1', $result['item']['senses'][0]['articles']['items'][0]['id']);
        self::assertSame([], $result['item']['mentions']['groups']);
    }

    public function test_owner_only_entry_returns_one_hop_redirect_destination_from_shared_seo_decision(): void
    {
        $sense = new DictionaryConcept('sense-redirect', 'Westminster', '', DictionaryConcept::APPROVED, 'music', 'owner-music', '/ban-nhac/westminster/');
        $entry = new LexicalEntry('entry-redirect', 'Westminster', 'westminster', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'westminster'], 1, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByPublicSlug(string $slug): LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'type' => 'music', 'id' => 'owner-music', 'source' => 'MAPPING']; }
        };

        $result = (new DictionaryDetailQuery(new class { public function listLabels(string $id): array { return []; } }, $entries, null, static fn (): array => ['identity' => ['type' => 'music', 'id' => 'owner-music', 'title' => 'Westminster', 'url' => '/ban-nhac/westminster/'], 'knowledge' => ['items' => []], 'relation_sections' => []]))->detail('westminster');

        self::assertSame('REDIRECT', $result['status']);
        self::assertSame('/ban-nhac/westminster/', $result['destination_url']);
        self::assertSame('/ban-nhac/westminster/', $result['seo']['canonical']);
        self::assertFalse($result['seo']['sitemap']);
    }

    public function test_persisted_compatibility_dictionary_slug_redirects_directly_to_owner(): void
    {
        $concept = new DictionaryConcept('compat-component', 'Côn hoa thị', '', DictionaryConcept::APPROVED, 'component', 'component-1', '/legacy/con-hoa-thi/', ['public_slug' => 'con-hoa-thi']);
        $concepts = new class($concept) {
            public function __construct(private DictionaryConcept $concept) {}
            public function listApproved(int $limit = 2000): array { return [$this->concept]; }
            public function listLabels(string $id): array { return []; }
        };
        $entries = new class {
            public function listEntries(int $limit = 2000): array { return []; }
            public function findDurableForConcept(string $conceptId): ?LexicalEntry { return null; }
        };

        $result = (new DictionaryDetailQuery($concepts, $entries, static fn (string $type, string $id, ?string $url): ?string => '/linh-kien/con-hoa-thi/'))->detail('con-hoa-thi');

        self::assertSame('REDIRECT', $result['status']);
        self::assertSame('/linh-kien/con-hoa-thi/', $result['destination_url']);
    }

    public function test_retired_durable_entry_blocks_legacy_compatibility_redirect(): void
    {
        $concept = new DictionaryConcept('retired-sense', 'Thuật ngữ cũ', '', DictionaryConcept::APPROVED, 'component', 'component-1', '/legacy/thuat-ngu-cu/', ['public_slug' => 'thuat-ngu-cu']);
        $retired = new LexicalEntry('retired-entry', 'Thuật ngữ cũ', 'thuat-ngu-cu', DictionaryConcept::RETIRED, 'vi-VN', ['public_slug' => 'thuat-ngu-cu'], 2, [$concept->conceptId]);
        $concepts = new class($concept) {
            public function __construct(private DictionaryConcept $concept) {}
            public function listApproved(int $limit = 2000): array { return [$this->concept]; }
            public function listLabels(string $id): array { return []; }
        };
        $entries = new class($retired) {
            public function __construct(private LexicalEntry $retired) {}
            public function listEntries(int $limit = 2000): array { return []; }
            public function findDurableForConcept(string $conceptId): ?LexicalEntry { return $this->retired; }
        };

        $result = (new DictionaryDetailQuery($concepts, $entries, static fn (): string => '/linh-kien/thuat-ngu-cu/'))->detail('thuat-ngu-cu');

        self::assertSame('NOT_FOUND', $result['status']);
    }

    public function test_delegated_component_entry_uses_persisted_owner_current_path_for_one_hop_redirect(): void
    {
        $sense = new DictionaryConcept('sense-component', 'Côn hoa thị', '', DictionaryConcept::APPROVED, 'component', 'component-1');
        $entry = new LexicalEntry('entry-component', 'Côn hoa thị', 'côn hoa thị', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'con-hoa-thi'], 1, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByPublicSlug(string $slug): LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'type' => 'component', 'id' => 'component-1', 'source' => 'MAPPING']; }
        };

        $result = (new DictionaryDetailQuery(
            new class { public function listLabels(string $id): array { return []; } },
            $entries,
            null,
            static fn (): array => ['identity' => ['type' => 'component', 'id' => 'component-1', 'current_path' => '/linh-kien/con-hoa-thi/'], 'knowledge' => ['items' => []], 'relation_sections' => []],
        ))->detail('con-hoa-thi');

        self::assertSame('REDIRECT', $result['status']);
        self::assertSame('/linh-kien/con-hoa-thi/', $result['destination_url']);
        self::assertFalse($result['indexable']);
        self::assertFalse($result['seo']['sitemap']);
    }

    public function test_delegated_component_entry_without_owner_current_path_fails_closed_without_dictionary_page(): void
    {
        $sense = new DictionaryConcept('sense-component-missing', 'Côn hoa thị', '', DictionaryConcept::APPROVED, 'component', 'component-missing');
        $entry = new LexicalEntry('entry-component-missing', 'Côn hoa thị', 'côn hoa thị', DictionaryConcept::APPROVED, 'vi-VN', ['public_slug' => 'con-hoa-thi'], 1, [$sense->conceptId]);
        $entries = new class($entry, $sense) {
            public function __construct(private LexicalEntry $entry, private DictionaryConcept $sense) {}
            public function findByPublicSlug(string $slug): LexicalEntry { return $this->entry; }
            public function listSenses(LexicalEntry $entry): array { return [$this->sense]; }
            public function listForms(LexicalEntry $entry): array { return []; }
            public function semanticReference(string $entryId, string $senseId): array { return ['status' => 'AVAILABLE', 'type' => 'component', 'id' => 'component-missing', 'source' => 'MAPPING']; }
        };

        $result = (new DictionaryDetailQuery(
            new class { public function listLabels(string $id): array { return []; } },
            $entries,
            null,
            static fn (): array => ['identity' => ['type' => 'component', 'id' => 'component-missing'], 'knowledge' => ['items' => []], 'relation_sections' => []],
        ))->detail('con-hoa-thi');

        self::assertSame('INCOMPLETE', $result['status']);
        self::assertNull($result['destination_url']);
        self::assertFalse($result['indexable']);
    }
}
