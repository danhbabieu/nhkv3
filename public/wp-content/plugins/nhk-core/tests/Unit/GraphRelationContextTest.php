<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Graph\GraphRelationContextPolicy;
use NHK\Core\Domain\Graph\GraphRelationContext;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class GraphRelationContextTest extends TestCase
{
    public function test_context_round_trips_relation_identity_scope_revisions_provenance_and_evidence_references(): void
    {
        $edge = UuidCodec::newV7();
        $context = GraphRelationContext::create(
            UuidCodec::newV7(),
            $edge,
            4,
            7,
            'component_music_scope',
            'component',
            'component-1',
            'EXTERNAL_RESEARCH',
            [['evidence_id' => UuidCodec::newV7()]],
            hash('sha256', 'approval'),
            'ctx-1',
        );

        $payload = $context->toArray();

        self::assertSame($edge, $payload['edge_uuid']);
        self::assertSame(4, $payload['source_revision']);
        self::assertSame(7, $payload['target_revision']);
        self::assertSame('component_music_scope', $payload['scope_code']);
        self::assertSame('EXTERNAL_RESEARCH', $payload['provenance_class']);
        self::assertCount(1, $payload['evidence_refs']);
        self::assertArrayNotHasKey('title', $payload);
        self::assertArrayNotHasKey('name', $payload);
        self::assertArrayNotHasKey('url', $payload);
        self::assertArrayNotHasKey('description', $payload);
    }

    public function test_policy_rejects_relation_context_payload_duplication(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GRAPH_RELATION_CONTEXT_PAYLOAD_FORBIDDEN');

        GraphRelationContextPolicy::assertInput([
            'edge_uuid' => UuidCodec::newV7(),
            'source_revision' => 1,
            'target_revision' => 1,
            'scope_code' => 'component_music_scope',
            'scope_subject_type' => 'component',
            'scope_subject_id' => 'component-1',
            'provenance_class' => 'EXTERNAL_RESEARCH',
            'evidence_refs' => [],
            'approval_fingerprint' => hash('sha256', 'approval'),
            'idempotency_key' => 'ctx-1',
            'title' => 'forbidden duplicate payload',
        ]);
    }

    public function test_policy_requires_canonical_evidence_ids_and_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GRAPH_RELATION_CONTEXT_EVIDENCE_REQUIRED');

        GraphRelationContextPolicy::assertInput([
            'edge_uuid' => UuidCodec::newV7(),
            'source_revision' => 1,
            'target_revision' => 1,
            'scope_code' => 'component_music_scope',
            'scope_subject_type' => 'component',
            'scope_subject_id' => 'component-1',
            'provenance_class' => 'EXTERNAL_RESEARCH',
            'evidence_refs' => [],
            'approval_fingerprint' => hash('sha256', 'approval'),
            'idempotency_key' => 'ctx-1',
        ]);
    }
}
