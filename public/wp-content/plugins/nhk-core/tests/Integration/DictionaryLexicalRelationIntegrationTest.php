<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Domain\Dictionary\DictionaryLexicalRelation;
use NHK\Core\Infrastructure\Dictionary\WpdbDictionaryLexicalRelationRepository;
use NHK\Core\Infrastructure\Migration\DictionaryLexicalRelationMigration025;
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class DictionaryLexicalRelationIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $owned = [];

    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        require_once dirname(__DIR__, 2) . '/nhk-core.php';
        (new DictionaryLexicalRelationMigration025())->up();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        TestDatabaseGuard::requireTestDatabase();
        foreach ($this->owned as $id) {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . $wpdb->prefix . 'nhk_dictionary_lexical_relations WHERE relation_uuid=%s',
                UuidCodec::toBinary($id),
            ));
        }
    }

    public function test_fresh_wpdb_repository_reads_created_relation_and_full_lifecycle(): void
    {
        global $wpdb;
        $relation = DictionaryLexicalRelation::create(
            UuidCodec::newV7(),
            UuidCodec::newV7(),
            null,
            UuidCodec::newV7(),
            null,
            DictionaryLexicalRelation::RELATED,
            ['source' => 'TEST_RUNTIME_FIXTURE'],
            'lexical-integration-' . bin2hex(random_bytes(8)),
        );
        $this->owned[] = $relation->relationUuid;

        $saved = (new WpdbDictionaryLexicalRelationRepository($wpdb))->create($relation);
        $fresh = new WpdbDictionaryLexicalRelationRepository($wpdb);
        self::assertSame($relation->relationUuid, $saved->relationUuid);
        self::assertNotNull($fresh->findByUuid($relation->relationUuid));
        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'nhk_dictionary_lexical_relations WHERE relation_uuid=%s AND source_sense_uuid IS NULL AND target_sense_uuid IS NULL',
            UuidCodec::toBinary($relation->relationUuid),
        )));

        $updated = $fresh->update(new DictionaryLexicalRelation(
            $relation->relationUuid, $relation->sourceEntryUuid, null, $relation->targetEntryUuid, null,
            $relation->kind, ['source' => 'UPDATED'], $relation->idempotencyKey, $relation->state,
            $relation->revision, $relation->createdAt, $relation->updatedAt, $relation->retiredAt,
        ), 1);
        self::assertSame(2, $updated->revision);
        $retired = $fresh->retire($updated, 2);
        self::assertSame(DictionaryLexicalRelation::RETIRED, $retired->state);
        self::assertSame(3, $fresh->findByUuid($relation->relationUuid)?->revision);
        $active = $fresh->reactivate($retired, 3);
        self::assertSame(DictionaryLexicalRelation::ACTIVE, $active->state);
        self::assertSame(4, $fresh->findByUuid($relation->relationUuid)?->revision);
    }
}
