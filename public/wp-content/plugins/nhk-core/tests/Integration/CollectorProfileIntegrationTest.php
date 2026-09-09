<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Article\ArticleResearchPreflight;
use NHK\Core\Application\Collector\CollectorProfileQuery;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class CollectorProfileIntegrationTest extends TestCase
{
    private const ROOT_ID = '01a07614-832d-7f27-959c-74eb0cd63f3e';
    private const ROOT_KEY = 'nhk:classification:clock-type.cuckoo-clock';
    private const OTHER_ID = '01a07614-832d-7f27-959c-74eb0cd63f41';
    private const FIXTURE_PREFIX = 'collector-integration-';

    /** @var list<string> */
    private array $claimIds = [];
    private ?string $sourceId = null;
    private ?string $evidenceId = null;

    protected function setUp(): void
    {
        global $wpdb;
        if (getenv('NHK_WP_TEST_PATH') === false) self::fail('Collector acceptance requires NHK_WP_TEST_PATH=public.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();

        $this->cleanupFixture();
        $authority = new WpdbAuthorityRepository();
        $authority->create(new AuthorityEntity(self::ROOT_ID, 'classification', self::ROOT_KEY, 'Đồng hồ chim cúc cu', 1, [], AuthorityState::ACTIVE, 1));
        $authority->create(new AuthorityEntity(self::OTHER_ID, 'classification', self::FIXTURE_PREFIX . 'other-branch', 'Nhánh ngoài Collector', 1, [], AuthorityState::ACTIVE, 1));

        $source = (new WpdbSourceRepository($wpdb))->create(new Source(
            UuidCodec::newV7(),
            self::FIXTURE_PREFIX . 'catalog-source',
            'Collector integration catalog',
            'catalog',
            'https://example.test/collector-catalog',
            ['visibility' => 'PUBLIC'],
        ));
        $this->sourceId = $source->canonicalId;

        $claims = new WpdbKnowledgeRepository($wpdb);
        for ($index = 1; $index <= 55; $index++) {
            $facet = match ($index) {
                1 => 'display_form',
                2 => 'case_styles',
                3 => 'running_duration',
                default => 'movement_family',
            };
            $claim = $claims->create(new KnowledgeClaim(
                UuidCodec::newV7(),
                self::FIXTURE_PREFIX . 'claim-' . $index,
                'Ghi nhận Collector Cuckoo ' . $index,
                'fact',
                ['metadata' => ['subject_id' => self::ROOT_ID, 'facet' => 'configuration', 'collector_facet' => $facet, 'scope' => 'entity']],
            ));
            $this->claimIds[] = $claim->canonicalId;
            if ($index === 1) {
                $evidence = (new WpdbEvidenceRepository($wpdb))->create(new Evidence(
                    UuidCodec::newV7(),
                    $claim->canonicalId,
                    $source->canonicalId,
                    'supports',
                    'Evidence for the Collector integration fixture.',
                    'https://example.test/collector-catalog#claim-1',
                    true,
                    1,
                    ['visibility' => 'PUBLIC'],
                ));
                $this->evidenceId = $evidence->canonicalId;
            }
        }

        (new WpdbKnowledgeRepository($wpdb))->create(new KnowledgeClaim(
            UuidCodec::newV7(),
            self::FIXTURE_PREFIX . 'unrelated-claim',
            'Không thuộc nhánh Đồng hồ chim cúc cu.',
            'fact',
            ['metadata' => ['subject_id' => self::OTHER_ID, 'facet' => 'configuration', 'collector_facet' => 'running_duration', 'scope' => 'entity']],
        ));
    }

    protected function tearDown(): void
    {
        $this->cleanupFixture();
    }

    public function test_wpdb_profile_paginates_full_branch_and_article_preflight_preserves_scope(): void
    {
        global $wpdb;
        $profile = new CollectorProfileQuery(
            new WpdbAuthorityRepository(),
            new WpdbKnowledgeRepository($wpdb),
            new WpdbEvidenceRepository($wpdb),
            new WpdbSourceRepository($wpdb),
        );
        $first = $profile->build(self::ROOT_ID, 1, 50);
        $second = $profile->build(self::ROOT_ID, 2, 50);
        $knowledge = array_merge($first['knowledge'], $second['knowledge']);

        self::assertSame('available', $first['status']);
        self::assertSame(self::ROOT_ID, $first['subject']['uuid']);
        self::assertSame(self::ROOT_KEY, $first['subject']['stable_key']);
        self::assertSame(55, $first['coverage']['knowledge_count']);
        self::assertSame(50, $first['coverage']['knowledge_returned']);
        self::assertTrue($first['coverage']['knowledge_has_next_page']);
        self::assertSame(5, $second['coverage']['knowledge_returned']);
        self::assertFalse($second['coverage']['knowledge_has_next_page']);
        self::assertSame(55, count(array_unique(array_column($knowledge, 'uuid'))));
        self::assertNotContains('Không thuộc nhánh Đồng hồ chim cúc cu.', array_column($knowledge, 'text'));
        self::assertSame(1, array_values(array_filter($knowledge, fn (array $item): bool => ($item['evidence_count'] ?? 0) === 1))[0]['evidence_count'] ?? null);
        self::assertSame(1, count($first['facets']['display_form'] ?? []));
        self::assertSame(1, count($first['facets']['case_styles'] ?? []));
        self::assertSame(0, $first['coverage']['media_count']);
        self::assertFalse($first['coverage']['media_complete']);
        self::assertSame(0, $first['coverage']['video_count']);
        self::assertFalse($first['coverage']['video_complete']);

        $scopedKnowledge = array_map(static fn (array $item): array => $item + ['subject_id' => self::ROOT_ID], $knowledge);
        $preflight = new ArticleResearchPreflight(
            static fn (array $input): array => ['status' => 'resolved', 'primary' => ['id' => self::ROOT_ID, 'type' => 'classification'], 'subjects' => [['id' => self::ROOT_ID, 'type' => 'classification']]],
            static fn (array $input): array => [
                'status' => 'available',
                'knowledge' => array_merge($scopedKnowledge, [['subject_id' => self::OTHER_ID, 'text' => 'Unrelated inventory claim']]),
                'media' => [['subject_id' => self::OTHER_ID, 'id' => 'unrelated-media']],
                'videos' => [['subject_id' => self::OTHER_ID, 'id' => 'unrelated-video']],
                'posts' => [],
                'relations' => [],
                'categories' => [['slug' => 'tri-thuc-dong-ho', 'name' => 'Tri thức đồng hồ']],
                'article_media' => ['media_complete' => false],
            ],
            static fn (array $relation): array => ['eligible' => false],
        );
        $result = $preflight->research('Đồng hồ chim cúc cu', ['type' => 'classification'], ['planned_title' => 'Đồng hồ chim cúc cu — checklist cho người sưu tầm']);

        self::assertTrue($result->readyForDraft);
        self::assertSame(55, count($result->inventory['knowledge']));
        self::assertNotContains('Unrelated inventory claim', array_column($result->inventory['knowledge'], 'text'));
        self::assertSame([], $result->inventory['media']);
        self::assertSame([], $result->inventory['videos']);
        self::assertFalse($result->mediaPlan['media_complete']);
        self::assertSame('Tri thức đồng hồ', $result->categoryPlan['category']['name']);
        self::assertSame('Đồng hồ chim cúc cu — checklist cho người sưu tầm', $result->seoBlueprint['title_intent']);
    }

    private function cleanupFixture(): void
    {
        TestDatabaseGuard::assertDestructiveAllowed('nhk_v3_test');
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_evidence WHERE source_uuid=%s OR claim_uuid IN (SELECT canonical_uuid FROM ' . $wpdb->prefix . 'nhk_knowledge_claims WHERE stable_key LIKE %s)', $this->sourceId !== null ? UuidCodec::toBinary($this->sourceId) : str_repeat("\0", 16), self::FIXTURE_PREFIX . '%'));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_knowledge_claims WHERE stable_key LIKE %s', self::FIXTURE_PREFIX . '%'));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_sources WHERE stable_key LIKE %s', self::FIXTURE_PREFIX . '%'));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_entities WHERE canonical_uuid IN (%s,%s) OR stable_key=%s', UuidCodec::toBinary(self::ROOT_ID), UuidCodec::toBinary(self::OTHER_ID), self::FIXTURE_PREFIX . 'other-branch'));
        $this->claimIds = [];
        $this->sourceId = null;
        $this->evidenceId = null;
    }
}
