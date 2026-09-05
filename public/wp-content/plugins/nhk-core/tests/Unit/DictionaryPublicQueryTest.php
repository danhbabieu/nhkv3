<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryPublicQuery;
use NHK\Core\Contracts\Dictionary\DictionaryConceptRepository;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, DictionaryLabel};
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

        self::assertSame('/ban-nhac/westminster/', $packet['items'][0]['url']);
        self::assertFalse($packet['items'][0]['dedicated']);
        self::assertSame('/tu-dien/vai-bo/', $packet['items'][1]['url']);
        self::assertTrue($packet['items'][1]['dedicated']);
        self::assertSame('/media/vai-bo.webp', $packet['items'][1]['image']['url']);
    }

    public function test_detail_does_not_create_duplicate_page_for_owner_delegated_concept(): void
    {
        $owner = new DictionaryConcept('c1', 'Westminster', 'Bản nhạc được tra cứu.', DictionaryConcept::APPROVED, 'music', 'music-1', '/ban-nhac/westminster/', ['public_slug' => 'westminster']);
        $repo = $this->repository([$owner], ['c1' => []]);

        $result = (new DictionaryPublicQuery($repo))->detail('westminster');

        self::assertSame('REDIRECT', $result['status']);
        self::assertSame('/ban-nhac/westminster/', $result['destination_url']);
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
        };
    }
}
