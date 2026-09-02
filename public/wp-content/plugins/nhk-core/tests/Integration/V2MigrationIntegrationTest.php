<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Migration\V2MigrationService;
use PHPUnit\Framework\TestCase;

final class V2MigrationIntegrationTest extends TestCase
{
    public function test_retired_writer_fails_closed_before_any_semantic_write(): void
    {
        $database = new class {
            public string $prefix = 'wp_';
            public int $writeCalls = 0;

            public function __call(string $name, array $arguments): never
            {
                $this->writeCalls++;
                throw new \LogicException('Retired V2 writer attempted a database operation.');
            }
        };

        try {
            (new V2MigrationService($database))->apply([['type' => 'brand', 'stable_key' => 'retired-writer-fixture']]);
            self::fail('The retired V2 writer must remain unavailable.');
        } catch (\NHK\Core\Governance\Exception\GovernanceException $exception) {
            self::assertStringContainsString('historical V2 migration writer is retired', $exception->getMessage());
        }

        self::assertSame(0, $database->writeCalls);
    }
}
