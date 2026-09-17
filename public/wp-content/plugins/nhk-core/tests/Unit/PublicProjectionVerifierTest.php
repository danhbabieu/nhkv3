<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Governance\PublicProjectionVerifier;
use NHK\Core\Domain\Video\Video;
use PHPUnit\Framework\TestCase;

final class PublicProjectionVerifierTest extends TestCase
{
    public function test_video_requires_canonical_readback_valid_reference_and_route(): void
    {
        $video = Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Public Clock');
        $verifier = new PublicProjectionVerifier(
            static fn (string $type, string $id): Video => $video,
            static fn (string $type, object $owner): string => '/video/public-clock/',
        );

        $result = $verifier->verify([
            'canonical_readback' => ['canonical_id' => $video->canonicalId],
        ], 'video', $video->canonicalId);

        self::assertSame('VERIFIED', $result['status']);
        self::assertTrue($result['projection_available']);
        self::assertSame('/video/public-clock/', $result['route']);
    }

    public function test_supporting_owner_is_verified_without_inventing_a_public_route(): void
    {
        $owner = (object) ['id' => 'source-1'];
        $verifier = new PublicProjectionVerifier(
            static fn (string $type, string $id): object => $owner,
            static fn (string $type, object $resolved): ?string => null,
        );

        $result = $verifier->verify([
            'canonical_readback' => ['canonical_id' => 'source-1'],
        ], 'source', 'source-1');

        self::assertSame('CANONICAL_OWNER_VERIFIED', $result['status']);
        self::assertTrue($result['projection_available']);
        self::assertNull($result['public_eligible']);
        self::assertNull($result['route']);
    }

    public function test_missing_route_fails_closed(): void
    {
        $video = Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Public Clock');
        $verifier = new PublicProjectionVerifier(
            static fn (string $type, string $id): Video => $video,
            static fn (string $type, object $owner): ?string => null,
        );

        $this->expectExceptionMessage('PUBLIC_PROJECTION_NOT_AVAILABLE');
        $verifier->verify([
            'canonical_readback' => ['canonical_id' => $video->canonicalId],
        ], 'video', $video->canonicalId);
    }
}
