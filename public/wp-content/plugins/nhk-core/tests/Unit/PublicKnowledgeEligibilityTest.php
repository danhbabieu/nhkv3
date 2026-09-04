<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Core\Application\Entity\{PublicEndpointEligibilityResolver, PublicEntityEligibilityPolicy, PublicRouteResolver};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Infrastructure\Http\PublicKnowledgeRoutes;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;
final class PublicKnowledgeEligibilityTest extends TestCase
{
    public function test_atomic_knowledge_pages_are_not_public_html_routes(): void { self::assertFalse(PublicKnowledgeRoutes::atomicDetailIsPublic()); self::assertNull(PublicKnowledgeRoutes::publicAtomicDetail(null, 'claim-key')); }
    public function test_unknown_knowledge_projection_fails_closed(): void { $authority = new InMemoryAuthorityRepository(); $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types); $resolver = new PublicEndpointEligibilityResolver(new PublicEntityEligibilityPolicy($authority, $types, new PublicRouteResolver($authority, $types))); $result = $resolver->evaluate(['target_type' => 'knowledge_claim', 'target_id' => '550e8400-e29b-41d4-a716-446655440000']); self::assertFalse($result['eligible']); self::assertSame('NO_PUBLIC_ROUTE', $result['reason']); }
}
