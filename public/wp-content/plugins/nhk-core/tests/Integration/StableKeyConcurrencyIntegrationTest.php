<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Authority\Exception\StableKeyCollision;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Migration\{AuthorityMigration002, GraphMigration001};
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class StableKeyConcurrencyIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) {
            self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        }
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        (new GraphMigration001())->up();
        (new AuthorityMigration002())->up();
    }

    public function test_same_key_same_command_has_one_canonical_uuid_under_two_connections(): void
    {
        $key = 'race-same-' . bin2hex(random_bytes(5));
        $results = $this->runActors($key, 'Concurrent Brand', 'Concurrent Brand');
        self::assertCount(2, array_filter($results, static fn (array $result): bool => $result['status'] === 'ok'));
        self::assertSame($results[0]['id'], $results[1]['id']);
        $this->deleteFixture($key);
    }

    public function test_same_key_different_command_has_one_winner_and_collision(): void
    {
        $key = 'race-different-' . bin2hex(random_bytes(5));
        $results = $this->runActors($key, 'Winner A', 'Winner B');
        self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['status'] === 'ok'));
        self::assertCount(1, array_filter($results, static fn (array $result): bool => $result['status'] === 'collision'));
        $this->deleteFixture($key);
    }

    /** @return list<array{status:string,id?:string,error?:string}> */
    private function runActors(string $key, string $nameA, string $nameB): array
    {
        if (!function_exists('pcntl_fork')) {
            self::fail('pcntl_fork is required for the stable-key concurrency integration test.');
        }
        $files = [tempnam(sys_get_temp_dir(), 'nhk-race-'), tempnam(sys_get_temp_dir(), 'nhk-race-')];
        $pids = [];
        foreach ([$nameA, $nameB] as $index => $name) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Unable to fork concurrency actor.');
            }
            if ($pid === 0) {
                global $wpdb;
                $wpdb = new \wpdb(DB_USER, DB_PASSWORD, 'nhk_v3_test', DB_HOST);
                $wpdb->set_prefix($GLOBALS['table_prefix']);
                $wpdb->suppress_errors(true);
                $types = new EntityTypeRegistry();
                $types->register(new EntityTypeDefinition('brand', 1, true, []));
                try {
                    $entity = (new AuthorityService(new WpdbAuthorityRepository(), $types))->create('brand', $key, $name);
                    file_put_contents($files[$index], json_encode(['status' => 'ok', 'id' => $entity->canonicalId]));
                } catch (StableKeyCollision $exception) {
                    file_put_contents($files[$index], json_encode(['status' => 'collision', 'error' => $exception->getMessage()]));
                } catch (\Throwable $exception) {
                    file_put_contents($files[$index], json_encode(['status' => 'error', 'error' => $exception->getMessage()]));
                }
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        $results = array_map(static fn (string $file): array => json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR), $files);
        foreach ($files as $file) {
            unlink($file);
        }
        return $results;
    }

    private function deleteFixture(string $key): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}nhk_entities WHERE entity_type=%s AND stable_key=%s", 'brand', $key));
    }
}
