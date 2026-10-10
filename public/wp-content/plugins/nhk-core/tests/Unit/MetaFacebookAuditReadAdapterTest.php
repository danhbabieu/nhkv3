<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use InvalidArgumentException;
use NHK\Core\Domain\FacebookAudit\FacebookAuditScope;
use NHK\Core\Infrastructure\FacebookAudit\{MetaFacebookAuditReadAdapter, MetaGraphHttpClient};
use PHPUnit\Framework\TestCase;

final class MetaFacebookAuditReadAdapterTest extends TestCase
{
    public function testIdentityUsesOnlyTheApprovedPagePathAndGraphGet(): void
    {
        $requests = [];
        $client = new MetaGraphHttpClient(static function (string $method, string $url, array $query) use (&$requests): array {
            $requests[] = [$method, $url, $query];
            return ['id' => '987654321', 'name' => 'Đồng Hồ Nhà Kho', 'link' => FacebookAuditScope::TARGET_URL];
        });
        $adapter = new MetaFacebookAuditReadAdapter($client, 'test-token');

        $identity = $adapter->verifyIdentity(FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, null));

        self::assertTrue($identity->verified);
        self::assertSame('987654321', $identity->pageId);
        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0][0]);
        self::assertStringContainsString('/donghonhakho.vn', $requests[0][1]);
        self::assertStringNotContainsString('another-page', $requests[0][1]);
    }

    public function testMissingCredentialFailsBeforeAnyRequest(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('META_CREDENTIALS_MISSING');
        new MetaFacebookAuditReadAdapter(new MetaGraphHttpClient(static fn (): array => []), '');
    }

    public function testUnverifiedScopeCannotReadPosts(): void
    {
        $calls = 0;
        $adapter = new MetaFacebookAuditReadAdapter(new MetaGraphHttpClient(static function () use (&$calls): array { $calls++; return []; }), 'test-token');

        $this->expectException(InvalidArgumentException::class);
        $adapter->pagePosts(FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, null), null, 10);
        self::assertSame(0, $calls);
    }

    public function testReadFailureDoesNotExposeCredential(): void
    {
        $adapter = new MetaFacebookAuditReadAdapter(new MetaGraphHttpClient(static function (): array { throw new \RuntimeException('request failed test-token'); }), 'test-token');
        $scope = FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, '987654321');
        $access = $adapter->inspectAccess($scope);

        self::assertStringNotContainsString('test-token', json_encode($access->complete(), JSON_THROW_ON_ERROR));
    }

    public function testKnownGroupsAreMarkedUnsupportedWithoutCallingStoppedGroupsApi(): void
    {
        $calls = [];
        $adapter = new MetaFacebookAuditReadAdapter(new MetaGraphHttpClient(static function (string $method, string $url, array $query) use (&$calls): array { $calls[] = $url; return ['id' => '987654321', 'name' => 'Page', 'link' => FacebookAuditScope::TARGET_URL]; }), 'test-token');
        $access = $adapter->inspectAccess(FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, '987654321'));

        self::assertSame('UNSUPPORTED', $access->status('known_groups'));
        self::assertFalse(array_filter($calls, static fn (string $url): bool => str_contains($url, '/groups')) !== []);
    }
}
