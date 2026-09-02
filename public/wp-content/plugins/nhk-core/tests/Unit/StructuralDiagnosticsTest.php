<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityService;
use NHK\Core\Application\Graph\{GraphService, StructuralContextQuery, StructuralDiagnostics};
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\{InMemoryAuthorityRepository, InMemoryGraphRepository};
use PHPUnit\Framework\TestCase;

final class StructuralDiagnosticsTest extends TestCase
{
    public function test_active_model_without_one_safe_parent_is_reported_as_structural_parent_missing(): void
    {
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new InMemoryAuthorityRepository(); $service = new AuthorityService($authority, $types);
        $model = $service->create('model', 'model-1', 'Model');
        $endpoints = new EndpointTypeRegistry(); $endpoints->register('model', new FakeEndpointResolver('model', [$model->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());

        $findings = (new StructuralDiagnostics($authority, new StructuralContextQuery($graph, $authority)))->read();

        self::assertSame('STRUCTURAL_PARENT_MISSING', $findings[0]['reason_code']);
        self::assertSame('model', $findings[0]['entity_type']);
        self::assertSame([], $findings[0]['parent_candidates']);
    }
}
