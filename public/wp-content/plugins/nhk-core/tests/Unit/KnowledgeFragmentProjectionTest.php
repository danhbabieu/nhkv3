<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Knowledge\{CurrentTruthPacket, KnowledgeFragmentProjector};
use NHK\Core\Domain\Knowledge\KnowledgeFacetProfile;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;
final class KnowledgeFragmentProjectionTest extends TestCase
{
    public function test_projection_is_fingerprintable_and_hides_internal_ids(): void
    {
        $subject = UuidCodec::newV7();
        $packet = new CurrentTruthPacket($subject, new KnowledgeFacetProfile('recognition', 'variant'), [], [], []);
        $projection = (new KnowledgeFragmentProjector())->project($packet, 'recognition');
        self::assertSame('recognition', $projection->fragment);
        self::assertNotSame('', $projection->dependencyFingerprint);
        self::assertStringNotContainsString($subject, $projection->content);
    }
}
