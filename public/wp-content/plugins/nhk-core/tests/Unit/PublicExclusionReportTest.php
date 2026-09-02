<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Entity\{PublicEntityEligibilityPolicy, PublicExclusionReport, PublicRouteResolver};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class PublicExclusionReportTest extends TestCase
{
    public function test_public_exclusion_report_does_not_rewrite_entity_state(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $repository = new InMemoryAuthorityRepository();
        $entity = (new AuthorityService($repository, $types))->create('model', 'orphan', 'Orphan');
        $before = $entity->revision;
        $routes = new PublicRouteResolver($repository, $types);

        $result = (new PublicExclusionReport(new PublicEntityEligibilityPolicy($repository, $types, $routes)))->evaluate($entity);

        self::assertFalse($result['eligible']);
        self::assertContains('STRUCTURAL_PARENT_MISSING', $result['reasons']);
        self::assertSame($before, $repository->findByCanonicalId($entity->canonicalId)->revision);
    }
}
