<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\PublicRouteResolver;
use NHK\Core\Application\Video\VideoPublicContextSelector;
use NHK\Core\Application\Video\VideoUrlPolicy;
use NHK\Core\Domain\Video\Video;
use PHPUnit\Framework\TestCase;

final class VideoUrlPolicyTest extends TestCase
{
    private const VIDEO_ID = '01a06815-1e51-7964-b004-1ba79e488ad1';

    public function test_governed_persisted_identity_projects_slug_only_video_url(): void
    {
        $video = new Video(self::VIDEO_ID, 'youtube', 'P4KaHX3LBOw', 'https://www.youtube.com/watch?v=P4KaHX3LBOw', 'Changed source title', [
            'public_identity' => ['current_slug' => 'odo-36-10-gai-carillon'],
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true],
            'editorial' => ['title' => 'NHK editorial title', 'summary' => 'Summary'],
            'hub' => ['primary' => '06'],
            'provenance' => ['kind' => 'YOUTUBE_SOURCE'],
            'semantic_attachments' => [['target_id' => '22222222-2222-4222-8222-222222222222']],
        ]);

        $result = (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector());

        self::assertTrue($result['eligible']);
        self::assertSame('/video/odo-36-10-gai-carillon/', $result['path']);
        self::assertStringNotContainsString(strtolower($video->externalVideoId), strtolower((string) $result['path']));
    }

    public function test_source_title_changes_do_not_change_the_persisted_video_url(): void
    {
        $video = new Video(self::VIDEO_ID, 'youtube', 'P4KaHX3LBOw', 'https://www.youtube.com/watch?v=P4KaHX3LBOw', 'New marketing title', [
            'public_identity' => ['current_slug' => 'odo-36-10-gai-carillon'],
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true],
            'editorial' => ['title' => 'New NHK title', 'summary' => 'Summary'],
            'hub' => ['primary' => '06'],
            'provenance' => ['kind' => 'YOUTUBE_SOURCE'],
            'semantic_attachments' => [['target_id' => '22222222-2222-4222-8222-222222222222']],
        ]);

        self::assertSame(
            '/video/odo-36-10-gai-carillon/',
            (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector())['path'],
        );
    }

    public function test_title_derived_video_candidate_never_appends_external_id(): void
    {
        self::assertSame(
            '/video/chat-am-lam-nen-ten-tuoi-nha-kho/',
            PublicRouteResolver::videoPath('Chất âm làm nên tên tuổi NHK', 'P4KaHX3LBOw'),
        );
    }

    public function test_context_selector_uses_governed_context_before_editorial_and_user_hint(): void
    {
        $selector = new VideoPublicContextSelector();

        self::assertSame(['source' => 'variant', 'value' => 'Ô Đô 36/10'], $selector->select([
            'variant' => ['name' => 'Ô Đô 36/10'],
            'model' => ['name' => 'Model'],
            'brand' => ['name' => 'Brand'],
            'music' => ['name' => 'Music'],
            'editorial_context' => 'Editorial context',
            'user_hint' => 'User hint',
            'marketing_title' => 'Marketing title',
        ]));
        self::assertSame(['source' => 'editorial_context', 'value' => 'Editorial context'], $selector->select([
            'editorial_context' => 'Editorial context',
            'user_hint' => 'User hint',
            'marketing_title' => 'Marketing title',
        ]));
        self::assertSame(['source' => 'user_hint', 'value' => 'User hint'], $selector->select([
            'user_hint' => 'User hint',
            'marketing_title' => 'Marketing title',
        ]));
        self::assertNull($selector->select(['marketing_title' => 'Marketing title']));
    }

    public function test_policy_blocks_video_without_governed_public_context(): void
    {
        $video = Video::fromUrl('https://youtu.be/P4KaHX3LBOw', 'Marketing title', [
            'source_snapshot' => ['availability' => 'available', 'embeddable' => true],
            'editorial' => ['title' => 'Editorial title', 'summary' => 'Summary'],
            'hub' => ['primary' => '06'],
            'provenance' => ['kind' => 'YOUTUBE_SOURCE'],
            'semantic_attachments' => [['target_id' => '22222222-2222-4222-8222-222222222222']],
        ], null, self::VIDEO_ID);

        $result = (new VideoUrlPolicy())->project($video, new VideoPublicContextSelector());

        self::assertFalse($result['eligible']);
        self::assertContains('PUBLIC_IDENTITY_NOT_PERSISTED', $result['blockers']);
    }
}
