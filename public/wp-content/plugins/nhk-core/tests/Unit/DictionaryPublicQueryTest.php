<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryPublicQuery;
use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};
use NHK\Core\Domain\Dictionary\LexicalEntry;
use PHPUnit\Framework\TestCase;

final class DictionaryPublicQueryTest extends TestCase
{
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
