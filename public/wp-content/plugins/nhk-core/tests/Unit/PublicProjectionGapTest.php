<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Entity\{PublicEndpointEligibilityResolver, PublicEntityEligibilityPolicy, PublicRouteResolver};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;
final class PublicProjectionGapTest extends TestCase
{
    public function test_album_and_gallery_are_explicit_registry_gaps(): void { $authority = new InMemoryAuthorityRepository(); $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types); $resolver = new PublicEndpointEligibilityResolver(new PublicEntityEligibilityPolicy($authority, $types, new PublicRouteResolver($authority, $types))); self::assertSame('REGISTRY_GAP', $resolver->projectionGap('album')['reason']); self::assertSame('REGISTRY_GAP', $resolver->projectionGap('gallery')['reason']); }
}
