<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\Media\{InvalidMedia, Media, MediaAsset, MediaUsage};
use NHK\Core\Domain\Video\{InvalidVideoReference, Video};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MediaVideoCoreTest extends TestCase
{
    public function test_media_identity_asset_and_usage_are_separate_contracts(): void
    {
        $mediaId = UuidCodec::newV7();
        $media = new Media($mediaId, 'media-odo-front', 'Odo front image', 'ready', ['source' => 'editorial']);
        $asset = new MediaAsset(UuidCodec::newV7(), $media->canonicalId, 'original', 'uploads/odo/front.jpg', hash('sha256', 'binary'), 'image/jpeg', 6, 1200, 800);
        $usage = new MediaUsage(UuidCodec::newV7(), $media->canonicalId, 'wp_post', '1:42', 'featured');
        self::assertSame($mediaId, $asset->mediaId);
        self::assertSame($mediaId, $usage->mediaId);
        self::assertSame('ready', $media->readiness);
        self::assertSame('original', $asset->kind);
        self::assertSame('featured', $usage->role);
    }

    public function test_checksum_is_a_duplicate_candidate_not_a_semantic_identity(): void
    {
        $checksum = hash('sha256', 'same-binary');
        $first = new Media(UuidCodec::newV7(), 'media-a', 'First semantic image');
        $second = new Media(UuidCodec::newV7(), 'media-b', 'Second semantic image');
        $one = new MediaAsset(UuidCodec::newV7(), $first->canonicalId, 'original', 'a.jpg', $checksum, 'image/jpeg', 11);
        $two = new MediaAsset(UuidCodec::newV7(), $second->canonicalId, 'original', 'b.jpg', $checksum, 'image/jpeg', 11);
        self::assertNotSame($one->mediaId, $two->mediaId);
        self::assertSame($one->checksum, $two->checksum);
    }

    public function test_invalid_media_contract_is_rejected(): void
    {
        $this->expectException(InvalidMedia::class);
        new MediaAsset(UuidCodec::newV7(), UuidCodec::newV7(), 'original', 'image.jpg', 'not-a-sha256', 'image/jpeg', 1);
    }

    public function test_youtube_urls_normalize_to_canonical_external_reference_without_local_asset(): void
    {
        $watch = Video::fromUrl('https://youtu.be/dQw4w9WgXcQ?t=20', 'Reference');
        $short = Video::fromUrl('https://www.youtube.com/shorts/dQw4w9WgXcQ');
        self::assertSame('youtube', $watch->platform);
        self::assertSame('dQw4w9WgXcQ', $watch->externalVideoId);
        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $watch->canonicalUrl);
        self::assertSame($watch->externalVideoId, $short->externalVideoId);
        self::assertNull($watch->thumbnailMediaId);
    }

    public function test_public_video_reference_is_fail_closed_for_unsupported_or_mismatched_values(): void
    {
        $valid = Video::fromUrl('https://youtu.be/dQw4w9WgXcQ');
        self::assertTrue($valid->hasValidPublicReference());

        $unsupported = new Video($valid->canonicalId, 'vimeo', 'dQw4w9WgXcQ', 'https://vimeo.com/dQw4w9WgXcQ');
        self::assertFalse($unsupported->hasValidPublicReference());

        $mismatched = new Video($valid->canonicalId, 'youtube', 'dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=AAAAAAAAAAA');
        self::assertFalse($mismatched->hasValidPublicReference());
    }

    public function test_non_youtube_or_malformed_reference_is_rejected(): void
    {
        $this->expectException(InvalidVideoReference::class);
        Video::fromUrl('https://example.com/video/123');
    }
}
