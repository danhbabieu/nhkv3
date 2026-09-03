<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Shared\Health\HealthCheck;
use NHK\Core\Application\Video\YouTubeApiConfiguration;
use PHPUnit\Framework\TestCase;
final class HealthCheckTest extends TestCase {
    public function test_health_contract_contains_separate_layer_results(): void {
        $migrations = new class {
            public function status(): array { return ['current' => 9, 'target' => 9]; }
            public function graphStorageReady(): bool { return true; }
            public function authorityStorageReady(): bool { return true; }
            public function governanceStorageReady(): bool { return true; }
            public function mediaStorageReady(): bool { return true; }
            public function videoStorageReady(): bool { return true; }
            public function knowledgeStorageReady(): bool { return true; }
        };

        $health = new HealthCheck($migrations, static fn (): array => ['physical_rows' => 0, 'hydrated_rows' => 0, 'query_rows' => 0, 'status' => 'EMPTY_VALID', 'reason_code' => null], new YouTubeApiConfiguration(static fn (): ?string => null, static fn (): ?string => 'environment-secret'));
        $result = $health->read();
        self::assertArrayHasKey('storage', $result['layers']);
        self::assertArrayHasKey('runtime', $result['layers']);
        self::assertArrayHasKey('hydration', $result['layers']);
        self::assertArrayHasKey('application', $result['layers']);
        self::assertArrayHasKey('rest', $result['layers']);
        self::assertSame(['configured' => true, 'source' => 'environment'], $result['youtube_api']);
        self::assertTrue($result['layers']['hydration']['ok']);
    }
}
