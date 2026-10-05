<?php
declare(strict_types=1);

namespace NHK\Core\Application\Graph;

use NHK\Core\Domain\Graph\GraphRelationContext;

final class GraphRelationContextPolicy
{
    private const PROVENANCE = ['OBSERVED_FROM_MEDIA', 'EXPLICIT_USER_KNOWLEDGE', 'CATALOG_SUPPORTED', 'EXTERNAL_RESEARCH', 'SYSTEM_INFERENCE'];

    /** @param array<string,mixed> $input */
    public static function assertInput(array $input, ?callable $evidenceResolver = null): void
    {
        $allowed = ['context_uuid', 'edge_uuid', 'source_revision', 'target_revision', 'scope_code', 'scope_subject_type', 'scope_subject_id', 'provenance_class', 'evidence_refs', 'approval_fingerprint', 'idempotency_key'];
        if (array_diff(array_keys($input), $allowed) !== []) throw new \InvalidArgumentException('GRAPH_RELATION_CONTEXT_PAYLOAD_FORBIDDEN');
        if ((int) ($input['source_revision'] ?? 0) < 1 || (int) ($input['target_revision'] ?? 0) < 1) throw new \InvalidArgumentException('GRAPH_RELATION_CONTEXT_OWNER_REVISION_REQUIRED');
        if (trim((string) ($input['scope_code'] ?? '')) === '' || trim((string) ($input['scope_subject_type'] ?? '')) === '' || trim((string) ($input['scope_subject_id'] ?? '')) === '') throw new \InvalidArgumentException('GRAPH_RELATION_CONTEXT_SCOPE_REQUIRED');
        $provenance = trim((string) ($input['provenance_class'] ?? ''));
        if (!in_array($provenance, self::PROVENANCE, true)) throw new \InvalidArgumentException('GRAPH_RELATION_CONTEXT_PROVENANCE_REQUIRED');
        $refs = is_array($input['evidence_refs'] ?? null) ? $input['evidence_refs'] : [];
        if ($refs === []) throw new \InvalidArgumentException('GRAPH_RELATION_CONTEXT_EVIDENCE_REQUIRED');
        foreach ($refs as $ref) {
            $id = is_array($ref) ? trim((string) ($ref['evidence_id'] ?? '')) : '';
            if ($id === '' || !\NHK\Core\Shared\Uuid\UuidCodec::isValid($id) || (is_callable($evidenceResolver) && !($evidenceResolver)($id))) throw new \InvalidArgumentException('GRAPH_RELATION_CONTEXT_EVIDENCE_INVALID');
        }
        if (trim((string) ($input['approval_fingerprint'] ?? '')) === '' || trim((string) ($input['idempotency_key'] ?? '')) === '') throw new \InvalidArgumentException('GRAPH_RELATION_CONTEXT_BINDING_REQUIRED');
    }

    /** @param array<string,mixed> $input */
    public static function create(array $input, ?callable $evidenceResolver = null): GraphRelationContext
    {
        self::assertInput($input, $evidenceResolver);
        return GraphRelationContext::create((string) ($input['context_uuid'] ?? \NHK\Core\Shared\Uuid\UuidCodec::newV7()), (string) $input['edge_uuid'], (int) $input['source_revision'], (int) $input['target_revision'], (string) $input['scope_code'], (string) $input['scope_subject_type'], (string) $input['scope_subject_id'], (string) $input['provenance_class'], (array) $input['evidence_refs'], (string) $input['approval_fingerprint'], (string) $input['idempotency_key']);
    }
}
