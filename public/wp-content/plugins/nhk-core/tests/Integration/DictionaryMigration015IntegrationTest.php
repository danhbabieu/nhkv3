<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Domain\Dictionary\{DictionaryCandidate, DictionaryCandidateState, DictionaryConcept, DictionaryLabel, DictionaryMention};
use NHK\Core\Infrastructure\Dictionary\{WpdbDictionaryCandidateRepository, WpdbDictionaryConceptRepository, WpdbDictionaryMentionRepository};
use NHK\Core\Infrastructure\Migration\DictionaryMigration015;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class DictionaryMigration015IntegrationTest extends TestCase
{
    private int $previousCurrent = 0;
    private int $previousTarget = 0;

    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        $this->previousCurrent = (int) get_option('nhk_core_migration_current', 0);
        $this->previousTarget = (int) get_option('nhk_core_migration_target', 0);
        $migration = new DictionaryMigration015();
        try { $migration->down(true); } catch (\Throwable) {}
        $migration->up();
    }

    protected function tearDown(): void
    {
        try { (new DictionaryMigration015())->down(true); } catch (\Throwable) {}
        update_option('nhk_core_migration_current', $this->previousCurrent, false);
        update_option('nhk_core_migration_target', $this->previousTarget, false);
    }

    public function test_migration_is_idempotent_and_creates_all_dictionary_tables(): void
    {
        global $wpdb;
        $migration = new DictionaryMigration015();
        $migration->up();
        $migration->up();

        self::assertTrue(DictionaryMigration015::schemaReady($wpdb));
        foreach (['nhk_dictionary_concepts', 'nhk_dictionary_labels', 'nhk_dictionary_candidates', 'nhk_dictionary_mentions'] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            self::assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
        }
    }

    public function test_candidate_suppression_mentions_and_concepts_persist_idempotently(): void
    {
        global $wpdb;
        $contextHash = hash('sha256', '{}');
        $now = gmdate('Y-m-d H:i:s');
        $candidateRepo = new WpdbDictionaryCandidateRepository($wpdb);
        $candidate = new DictionaryCandidate(UuidCodec::newV7(), 'côn lòng máng', $contextHash, ['Côn lòng máng'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 1, $now, $now, 1);
        $first = $candidateRepo->upsertObservation($candidate);
        $second = $candidateRepo->upsertObservation(new DictionaryCandidate(UuidCodec::newV7(), 'côn lòng máng', $contextHash, ['côn lòng máng'], DictionaryCandidateState::NEEDS_REVIEW, [], [], 1, $now, $now, 1));
        self::assertSame($first->candidateId, $second->candidateId);
        self::assertSame(2, $second->occurrences);

        $suppressed = new DictionaryCandidate($second->candidateId, $second->normalizedTerm, $second->contextHash, $second->rawForms, DictionaryCandidateState::DO_NOT_SUGGEST, $second->context, $second->suggestions, $second->occurrences, $second->firstSeenAt, $second->lastSeenAt, $second->revision + 1);
        $saved = $candidateRepo->saveDecision($suppressed, $second->revision);
        self::assertTrue($candidateRepo->suppressed('côn lòng máng', $contextHash));
        self::assertSame($saved->occurrences, $candidateRepo->upsertObservation($candidate)->occurrences);

        $mentionRepo = new WpdbDictionaryMentionRepository($wpdb);
        $fingerprint = hash('sha256', 'ARTICLE\0' . '42' . '\0côn lòng máng\0' . $contextHash);
        $mention = new DictionaryMention(UuidCodec::newV7(), $fingerprint, 'ARTICLE', '42', 'côn lòng máng', $contextHash, null, ['post_id' => 42], 'NORMAL', $now);
        $m1 = $mentionRepo->upsert($mention);
        $m2 = $mentionRepo->upsert(new DictionaryMention(UuidCodec::newV7(), $fingerprint, 'ARTICLE', '42', 'côn lòng máng', $contextHash, null, ['post_id' => 42], 'NORMAL', $now));
        self::assertSame($m1->mentionId, $m2->mentionId);
        self::assertCount(1, $mentionRepo->listBySource('ARTICLE', '42'));

        $conceptRepo = new WpdbDictionaryConceptRepository($wpdb);
        $conceptId = UuidCodec::newV7();
        $concept = $conceptRepo->createConcept(new DictionaryConcept($conceptId, 'Vai bò', 'Tên gọi dân gian Việt Nam.', DictionaryConcept::APPROVED, null, null, null, ['public_slug' => 'vai-bo', 'term_type' => 'COLLOQUIAL']));
        $conceptRepo->addLabel(new DictionaryLabel($conceptId, 'Vai bò', 'vai bò', DictionaryLabel::PREFERRED, 'vi-VN'));
        $conceptRepo->addLabel(new DictionaryLabel($conceptId, 'đồng hồ vai bò', 'đồng hồ vai bò', DictionaryLabel::COLLOQUIAL, 'vi-VN'));
        self::assertSame($conceptId, $concept->conceptId);
        self::assertCount(2, $conceptRepo->listLabels($conceptId));
        self::assertCount(1, $conceptRepo->findApprovedByNormalizedLabel('vai bò'));
    }
}
