<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticleIngestPreflight;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, PredicateRegistry};
use PHPUnit\Framework\TestCase;

final class ArticleIngestPreflightTest extends TestCase
{
    public function test_reconcile_requires_post_55_and_registered_semantic_commands(): void
    {
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:55']));
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));

        $result = (new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types))->check('1:55', 'reconcile', [[
            'slot' => 'brand', 'operation' => 'update', 'entity_type' => 'brand', 'subject_id' => 'brand-id', 'expected_revision' => 1, 'payload' => [],
        ]]);

        self::assertTrue($result->accepted);
        self::assertSame([], $result->reasons);
    }

    public function test_create_and_unknown_predicate_fail_closed(): void
    {
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:55']));
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));

        $result = (new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types))->check('1:55', 'create', [[
            'slot' => 'relation', 'operation' => 'relation_create', 'entity_type' => 'relation', 'subject_id' => 'relation', 'expected_revision' => 1,
            'payload' => ['source_type' => 'wp_post', 'source_key' => '1:55', 'predicate' => 'not_registered', 'target_type' => 'brand', 'target_key' => 'brand-id'],
        ]]);

        self::assertFalse($result->accepted);
        self::assertContains('UNSUPPORTED_OPERATION', $result->reasons);
    }

    public function test_evidence_creation_fails_closed_until_exact_duplicate_identity_exists(): void
    {
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:55']));
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('evidence', 1, true, []));

        $result = (new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types))->check('1:55', 'reconcile', [[
            'slot' => 'evidence', 'operation' => 'ingest', 'entity_type' => 'evidence', 'subject_id' => 'evidence', 'expected_revision' => 1,
            'payload' => ['claim_id' => 'claim', 'source_id' => 'source', 'excerpt' => 'evidence'],
        ]]);

        self::assertFalse($result->accepted);
        self::assertContains('EVIDENCE_IDEMPOTENCY_UNPROVEN', $result->reasons);
    }
}
