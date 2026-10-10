<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use InvalidArgumentException;
use NHK\Core\Contracts\FacebookAudit\FacebookAuditReadAdapter;
use NHK\Core\Domain\FacebookAudit\{AccessReport, FacebookAuditScope, FacebookValue, IdentityVerification, ReadPage};
use PHPUnit\Framework\TestCase;

final class FacebookAuditScopeTest extends TestCase
{
    public function testCanonicalPilotUrlCreatesAnUnverifiedScopeUntilIdentityIsReadBack(): void
    {
        $scope = FacebookAuditScope::forTarget('https://www.facebook.com/donghonhakho.vn/', null);

        self::assertSame('https://www.facebook.com/donghonhakho.vn', $scope->targetUrl);
        self::assertFalse($scope->isIdentityVerified());
        self::assertNull($scope->verifiedPageId);
    }

    public function testScopeMismatchIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FacebookAuditScope::forTarget('https://www.facebook.com/another-page', null);
    }

    public function testUnverifiedScopeCannotBeUsedForCollection(): void
    {
        $scope = FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, null);

        $this->expectException(InvalidArgumentException::class);
        $scope->assertCollectionReady();
    }

    public function testVerifiedPageIdLocksTheScope(): void
    {
        $scope = FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, null)
            ->withVerifiedPageId('123456789');

        self::assertTrue($scope->isIdentityVerified());
        self::assertSame('123456789', $scope->verifiedPageId);
        $scope->assertCollectionReady();
    }

    public function testValueStatesKeepNullUnavailableInaccessibleAndZeroDistinct(): void
    {
        self::assertSame('NULL', FacebookValue::nullValue()->state);
        self::assertSame('UNAVAILABLE', FacebookValue::unavailable()->state);
        self::assertSame('INACCESSIBLE', FacebookValue::inaccessible()->state);
        self::assertSame('KNOWN', FacebookValue::known(0)->state);
        self::assertSame(0, FacebookValue::known(0)->value);
    }

    public function testIdentityAccessAndPageResultsAreTyped(): void
    {
        $identity = IdentityVerification::verified('123', 'Đồng Hồ Nhà Kho', FacebookAuditScope::TARGET_URL);
        $access = new AccessReport(['page_metadata' => 'GRANTED', 'known_groups' => 'INACCESSIBLE']);
        $page = new ReadPage([['post_id' => 'p1']], 'cursor-2');

        self::assertTrue($identity->verified);
        self::assertSame('GRANTED', $access->status('page_metadata'));
        self::assertSame('INACCESSIBLE', $access->status('known_groups'));
        self::assertSame('cursor-2', $page->nextCursor);
    }

    public function testReadAdapterContractContainsOnlyReadMethods(): void
    {
        self::assertTrue(interface_exists(FacebookAuditReadAdapter::class));
        self::assertSame([
            'verifyIdentity',
            'inspectAccess',
            'pagePosts',
            'groupPosts',
        ], array_values(array_filter(get_class_methods(FacebookAuditReadAdapter::class), static fn (string $method): bool => $method !== '__construct')));
    }
}
