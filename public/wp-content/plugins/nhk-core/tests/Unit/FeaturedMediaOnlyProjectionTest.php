<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Media\WordPressMediaAttachmentBridge;
use NHK\Core\Application\Media\MediaService;
use PHPUnit\Framework\TestCase;

final class FeaturedMediaOnlyProjectionTest extends TestCase
{
    public function test_bridge_exposes_a_featured_only_projection_owner(): void
    {
        self::assertTrue(method_exists(WordPressMediaAttachmentBridge::class, 'projectFeaturedOnly'));
    }

    public function test_featured_only_projection_enforces_editorial_state_cas_before_projection(): void
    {
        $database = (object) ['prefix' => 'wp_'];
        $bridge = new WordPressMediaAttachmentBridge(
            $database,
            new MediaService(
                $this->createMock(\NHK\Core\Contracts\Media\MediaRepository::class),
                $this->createMock(\NHK\Core\Contracts\Media\MediaAssetRepository::class),
                $this->createMock(\NHK\Core\Contracts\Media\MediaUsageRepository::class),
            ),
            $this->createMock(\NHK\Core\Contracts\Media\MediaRepository::class),
            $this->createMock(\NHK\Core\Contracts\Media\MediaAssetRepository::class),
        );

        $this->expectExceptionMessage('EDITORIAL_STATE_CHANGED');
        $bridge->projectFeaturedOnly(469, '01a0d7ee-3e33-7366-88c6-287112b34936', str_repeat('a', 64));
    }

    public function test_featured_only_owner_does_not_delegate_to_article_composition(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Media/WordPressMediaAttachmentBridge.php');
        $start = strpos($source, 'public function projectFeaturedOnly');
        $end = strpos($source, 'public function attachmentForMedia', $start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString('set_post_thumbnail', $method);
        self::assertStringNotContainsString('synchronize(', $method);
        self::assertStringNotContainsString('wp_update_post', $method);
    }
}
