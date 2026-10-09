<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\SemanticEnrichmentRelationRegistry;
use NHK\Core\Domain\Graph\PredicateRegistry;
use PHPUnit\Framework\TestCase;

final class AssociatedWithPolicyTest extends TestCase
{
    public function test_associated_with_is_registered_with_the_exact_bounded_pairs(): void
    {
        $registry = new PredicateRegistry();
        $predicate = $registry->get('associated_with');

        self::assertSame('1.2.0', PredicateRegistry::VERSION);
        self::assertSame(['component', 'movement', 'variant'], $predicate->allowed_source_types);
        self::assertSame(['brand', 'movement', 'music', 'classification'], $predicate->allowed_target_types);
        self::assertSame('REQUIRED', $predicate->evidence_requirement);
        self::assertSame('REQUIRED', $predicate->provenance_requirement);
        self::assertTrue($predicate->constraints['bounded'] ?? false);
    }

    public function test_dictionary_media_usage_and_self_relations_are_rejected(): void
    {
        $registry = new SemanticEnrichmentRelationRegistry();

        self::assertFalse($registry->allows('dictionary', 'associated_with', 'brand'));
        self::assertFalse($registry->allows('component', 'associated_with', 'media_usage'));
        self::assertFalse($registry->allows('component', 'associated_with', 'component'));
        self::assertTrue($registry->allows('component', 'associated_with', 'music'));
    }

    public function test_stronger_predicate_wins_over_associated_with(): void
    {
        $registry = new SemanticEnrichmentRelationRegistry();

        self::assertSame('supports_music', $registry->preferredPredicate('movement', 'music', ['supports_music', 'associated_with']));
        self::assertSame('associated_with', $registry->preferredPredicate('component', 'music', ['associated_with']));
    }
}
