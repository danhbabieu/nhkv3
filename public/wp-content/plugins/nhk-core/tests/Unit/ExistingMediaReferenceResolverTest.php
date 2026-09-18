<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Media\ExistingMediaReferenceResolver;
use NHK\Core\Contracts\Media\{MediaAssetRepository, MediaRepository, WordPressMediaAttachmentIngestor};
use NHK\Core\Domain\Media\{Media, MediaAsset};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ExistingMediaReferenceResolverTest extends TestCase
{
    public function test_active_media_is_resolved_in_submitted_order_after_attachment_readback(): void
    {
        $first = UuidCodec::newV7();
        $second = UuidCodec::newV7();
        $media = $this->createMock(MediaRepository::class);
        $assets = $this->createMock(MediaAssetRepository::class);
        $attachments = $this->createMock(WordPressMediaAttachmentIngestor::class);
        $media->method('findByCanonicalId')->willReturnCallback(static fn (string $id): ?Media => $id === $first ? new Media($first, 'first-media', 'First') : ($id === $second ? new Media($second, 'second-media', 'Second') : null));
        $assets->method('listByMediaId')->willReturnCallback(static fn (string $id): array => [new MediaAsset(UuidCodec::newV7(), $id, 'derivative', 'uploads/' . $id . '.webp', str_repeat('a', 64), 'image/webp', 100, 10, 20, 'PUBLIC', ['wordpress_attachment_id' => $id === $first ? 11 : 22])]);
        $attachments->method('read')->willReturnCallback(static fn (int $id): ?array => ['attachment_id' => $id, 'filename' => 'safe-' . $id . '.webp', 'original_filename' => 'original-' . $id . '.jpg', 'mime' => 'image/webp', 'width' => 10, 'height' => 20, 'filesize' => 100, 'derivatives' => []]);

        $result = (new ExistingMediaReferenceResolver($media, $assets, $attachments))->resolve([$second, $first]);

        self::assertSame([$second, $first], array_column($result, 'media_id'));
        self::assertSame([22, 11], array_column($result, 'attachment_id'));
        self::assertSame(['verified', 'verified'], array_column($result, 'attachment_readback_status'));
    }

    public function test_unknown_or_inactive_media_fails_closed(): void
    {
        $media = $this->createMock(MediaRepository::class);
        $media->method('findByCanonicalId')->willReturn(null);
        $resolver = new ExistingMediaReferenceResolver($media, $this->createMock(MediaAssetRepository::class), $this->createMock(WordPressMediaAttachmentIngestor::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MEDIA_REFERENCE_NOT_FOUND');
        $resolver->resolve([UuidCodec::newV7()]);
    }

    public function test_same_media_reuse_requires_the_attachment_readback_to_confirm_the_exact_mapping(): void
    {
        $mediaId = UuidCodec::newV7();
        $otherMediaId = UuidCodec::newV7();
        $media = $this->createMock(MediaRepository::class);
        $assets = $this->createMock(MediaAssetRepository::class);
        $attachments = $this->createMock(WordPressMediaAttachmentIngestor::class);
        $media->method('findByCanonicalId')->willReturn(new Media($mediaId, 'media-574', 'Existing media'));
        $assets->method('listByMediaId')->willReturn([new MediaAsset(UuidCodec::newV7(), $mediaId, 'derivative', 'uploads/existing.webp', str_repeat('9', 64), 'image/webp', 100, 10, 20, 'PUBLIC', ['wordpress_attachment_id' => 574])]);
        $attachments->method('read')->with(574)->willReturn(['attachment_id' => 574, 'media_id' => $otherMediaId, 'filename' => 'existing.webp']);

        $this->expectExceptionMessage('MEDIA_ATTACHMENT_MAPPING_INCONSISTENT');
        (new ExistingMediaReferenceResolver($media, $assets, $attachments))->resolve([$mediaId]);
    }
}
