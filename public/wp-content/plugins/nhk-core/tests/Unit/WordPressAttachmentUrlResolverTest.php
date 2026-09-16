<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\Media\WordPressAttachmentUrlResolver;
use PHPUnit\Framework\TestCase;

final class WordPressAttachmentUrlResolverTest extends TestCase
{
    private function resolver(): WordPressAttachmentUrlResolver
    {
        return new WordPressAttachmentUrlResolver(new \stdClass(), ['https://demo.1945.vn'], '/wp-content/uploads');
    }

    public function test_same_site_attachment_url_normalizes_to_exact_upload_storage_path(): void
    {
        self::assertSame(
            '2026/09/IMG_4571-scaled.jpeg',
            $this->resolver()->relativeUploadPath('https://demo.1945.vn/wp-content/uploads/2026/09/IMG_4571-scaled.jpeg?ver=1'),
        );
    }

    public function test_foreign_host_is_rejected(): void
    {
        $this->expectExceptionMessage('EXISTING_MEDIA_URL_HOST_NOT_ALLOWED');
        $this->resolver()->relativeUploadPath('https://example.test/wp-content/uploads/2026/09/image.jpeg');
    }

    public function test_encoded_path_traversal_is_rejected(): void
    {
        $this->expectExceptionMessage('EXISTING_MEDIA_URL_PATH_TRAVERSAL');
        $this->resolver()->relativeUploadPath('https://demo.1945.vn/wp-content/uploads/2026/09/%2e%2e/private/image.jpeg');
    }

    public function test_encoded_backslash_path_traversal_is_rejected(): void
    {
        $this->expectExceptionMessage('EXISTING_MEDIA_URL_PATH_TRAVERSAL');
        $this->resolver()->relativeUploadPath('https://demo.1945.vn/wp-content/uploads/2026/09/%5c%2e%2e%5cprivate%5cimage.jpeg');
    }

    public function test_non_https_is_rejected(): void
    {
        $this->expectExceptionMessage('EXISTING_MEDIA_URL_INVALID');
        $this->resolver()->relativeUploadPath('http://demo.1945.vn/wp-content/uploads/2026/09/image.jpeg');
    }
}
