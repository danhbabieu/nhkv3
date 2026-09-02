<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Graph\Exception\{InvalidRelationSourceType, UnknownPredicate};
use NHK\Core\Infrastructure\Graph\InMemoryAuditSink;
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class BrandRelationshipRegistryTest extends TestCase
{
    public function test_approved_brand_relationships_have_exact_endpoints_and_cardinality(): void
    {
        $registry = new PredicateRegistry();
        $expected = [
            'model_of' => ['model', 'brand', 'ONE', 'MANY'],
            'variant_of' => ['variant', 'model', 'ONE', 'MANY'],
            'uses_movement' => ['variant', 'movement', 'MANY', 'MANY'],
            'supports_music' => ['movement', 'music', 'MANY', 'MANY'],
            'configured_with_music' => ['variant', 'music', 'MANY', 'MANY'],
            'observed_playing_music' => ['specimen', 'music', 'MANY', 'MANY'],
        ];

        foreach ($expected as $key => [$source, $target, $outbound, $inbound]) {
            $definition = $registry->get($key);
            self::assertSame([$source], $definition->allowed_source_types, $key);
            self::assertSame([$target], $definition->allowed_target_types, $key);
            self::assertSame($outbound, $definition->outbound_cardinality, $key);
            self::assertSame($inbound, $definition->inbound_cardinality, $key);
        }
        self::assertNotContains('brand_of', array_column($registry->all(), 'key'));
        self::assertNotContains('movement_of', array_column($registry->all(), 'key'));
    }

    public function test_invalid_relationship_endpoint_combinations_fail_closed(): void
    {
        [$service] = $this->service();
        foreach ([
            [new NodeReference('movement', 'movement-1'), 'model_of', new NodeReference('brand', 'brand-1')],
            [new NodeReference('variant', 'variant-1'), 'model_of', new NodeReference('brand', 'brand-1')],
            [new NodeReference('model', 'model-1'), 'variant_of', new NodeReference('variant', 'variant-1')],
            [new NodeReference('brand', 'brand-1'), 'uses_movement', new NodeReference('movement', 'movement-1')],
        ] as [$source, $predicate, $target]) {
            try {
                $service->create($source, $predicate, $target);
                self::fail('Invalid endpoint combination was accepted: ' . $predicate);
            } catch (InvalidRelationSourceType) {
                self::assertTrue(true);
            }
        }
    }

    public function test_unknown_predicate_fails_closed(): void
    {
        [$service] = $this->service();
        $this->expectException(UnknownPredicate::class);
        $service->create(new NodeReference('brand', 'brand-1'), 'unknown_relation', new NodeReference('brand', 'brand-2'));
    }

    private function service(): array
    {
        $endpoints = new EndpointTypeRegistry();
        foreach (['brand', 'model', 'variant', 'movement', 'music', 'specimen'] as $type) $endpoints->register($type, new FakeEndpointResolver($type, [$type . '-1', $type . '-2']));
        return [new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink())];
    }
}
