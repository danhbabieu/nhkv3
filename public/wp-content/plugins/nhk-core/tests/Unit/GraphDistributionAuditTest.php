<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\GraphDistributionAudit;
use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Tests\Support\InMemoryGraphDistributionReader;
use PHPUnit\Framework\TestCase;

final class GraphDistributionAuditTest extends TestCase
{
    public function test_audit_groups_active_edges_by_source_predicate_and_target_without_mutation_calls(): void
    {
        $audit = new GraphDistributionAudit(new InMemoryGraphDistributionReader([
            ['source_type' => 'wp_post', 'predicate' => 'about', 'target_type' => 'brand'],
            ['source_type' => 'wp_post', 'predicate' => 'about', 'target_type' => 'brand'],
        ]), new PredicateRegistry());

        $result = $audit->read();

        self::assertSame([['source_type' => 'wp_post', 'predicate' => 'about', 'target_type' => 'brand', 'edge_count' => 2]], $result['distribution']);
        self::assertSame(2, $result['active_edge_total']);
        self::assertSame(8, $result['registered_predicate_count']);
    }
}
