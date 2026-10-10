<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use InvalidArgumentException;
use NHK\Core\Application\FacebookAudit\{FacebookAuditCollector, FacebookAuditNormalizer};
use NHK\Core\Domain\FacebookAudit\{FacebookAuditScope, FacebookValue};
use NHK\Core\Infrastructure\FacebookAudit\{FixtureFacebookAuditReadAdapter, JsonFacebookAuditCheckpointStore};
use PHPUnit\Framework\TestCase;

final class FacebookAuditCollectionTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = dirname(__DIR__) . '/Fixtures/facebook-audit-pilot.json';
    }

    public function testFixtureCollectionReadsAllPagesAndLabelsGroupPostOrigin(): void
    {
        $scope = FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, '123456789');
        $collector = new FacebookAuditCollector(
            new FixtureFacebookAuditReadAdapter($this->fixture),
            new FacebookAuditNormalizer(),
            null,
            3,
            2,
        );

        $result = $collector->collect($scope);

        self::assertCount(3, $result->pagePosts);
        self::assertCount(2, $result->groupPosts);
        $groupKinds = array_column($result->groupPosts, 'group_post_kind');
        sort($groupKinds);
        self::assertSame(['PAGE_AUTHORED', 'PAGE_SHARED'], $groupKinds);
        self::assertSame('COMPLETE', $result->status);
    }

    public function testCheckpointResumeDoesNotDuplicateRows(): void
    {
        $scope = FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, '123456789');
        $path = tempnam(sys_get_temp_dir(), 'facebook-audit-checkpoint-');
        self::assertNotFalse($path);
        $store = new JsonFacebookAuditCheckpointStore($path);
        $collector = new FacebookAuditCollector(new FixtureFacebookAuditReadAdapter($this->fixture), new FacebookAuditNormalizer(), $store, 3, 2);

        $first = $collector->collect($scope);
        $second = $collector->collect($scope);

        self::assertCount(3, $first->pagePosts);
        self::assertCount(3, $second->pagePosts);
        self::assertSame(['p-1', 'p-2', 'p-3'], array_column($second->pagePosts, 'post_id'));
        @unlink($path);
    }

    public function testRetryableFixtureFailureIsRetriedAndDoesNotBecomeEmpty(): void
    {
        $scope = FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, '123456789');
        $adapter = new FixtureFacebookAuditReadAdapter($this->fixture, ['page_posts:2' => 1]);
        $result = (new FacebookAuditCollector($adapter, new FacebookAuditNormalizer(), null, 3, 2))->collect($scope);

        self::assertSame('COMPLETE', $result->status);
        self::assertCount(3, $result->pagePosts);
        self::assertSame([], $result->blockers);
    }

    public function testRepeatedCursorFailsClosed(): void
    {
        $scope = FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, '123456789');
        $adapter = new FixtureFacebookAuditReadAdapter($this->fixture, [], true);
        $result = (new FacebookAuditCollector($adapter, new FacebookAuditNormalizer(), null, 1, 2))->collect($scope);

        self::assertSame('BLOCKED', $result->status);
        self::assertContains('PAGE_REPEATED_CURSOR', $result->blockers);
    }

    public function testNormalizationPreservesAllMissingValueStatesAndZero(): void
    {
        $row = (new FacebookAuditNormalizer())->post([
            'post_id' => 'p-state',
            'reaction_count' => 0,
            'comment_count' => null,
            'share_count' => ['state' => 'UNAVAILABLE'],
            'video_views' => ['state' => 'INACCESSIBLE'],
        ], 'PAGE');

        self::assertSame('KNOWN', $row['reaction_count']->state);
        self::assertSame(0, $row['reaction_count']->value);
        self::assertSame('NULL', $row['comment_count']->state);
        self::assertSame('UNAVAILABLE', $row['share_count']->state);
        self::assertSame('INACCESSIBLE', $row['video_views']->state);
    }

    public function testUnverifiedScopeCannotCollect(): void
    {
        $scope = FacebookAuditScope::forTarget(FacebookAuditScope::TARGET_URL, null);

        $this->expectException(InvalidArgumentException::class);
        (new FacebookAuditCollector(new FixtureFacebookAuditReadAdapter($this->fixture), new FacebookAuditNormalizer()))->collect($scope);
    }
}
