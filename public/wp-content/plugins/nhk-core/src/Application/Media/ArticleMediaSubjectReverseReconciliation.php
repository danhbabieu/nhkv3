<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Graph\GraphReader;
use NHK\Core\Contracts\Media\{ArticleMediaBlueprintCasRepository, ArticleMediaBlueprintRepository};
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Graph\{GraphEdge, NodeReference};
use NHK\Core\Domain\Governance\Proposal;

/**
 * Plans and applies the bounded Article subject binding copied from one
 * unambiguous ACTIVE Graph about relation. Graph remains identity truth;
 * Article Media Blueprint remains contextual binding truth.
 */
final class ArticleMediaSubjectReverseReconciliation
{
    /** @param callable(NodeReference):?int|null $revisionReader */
    public function __construct(
        private GraphReader $graph,
        private AuthorityRepository $authority,
        private ArticleMediaBlueprintRepository $blueprints,
        private $revisionReader = null,
    ) {}

    /** @return array<string,mixed> */
    public function preview(string $endpointType, string $endpointKey): array
    {
        return $this->inspect($endpointType, $endpointKey, false);
    }

    /** @return array<string,mixed> */
    public function plan(string $endpointType, string $endpointKey): array
    {
        $inspection = $this->inspect($endpointType, $endpointKey, true);
        if (($inspection['status'] ?? '') !== 'READY') return $inspection;

        $payload = [
            'endpoint' => ['type' => 'wp_post', 'id' => $endpointKey],
            'subject_binding' => $inspection['subject_binding'],
            'expected_source_revision' => $inspection['source_revision'],
            'expected_blueprints' => $inspection['expected_blueprints'],
            'dependency_fingerprint' => $inspection['dependency_fingerprint'],
        ];
        $planFingerprint = hash('sha256', $this->json([
            'operation' => 'subject_bind',
            'entity_type' => 'wp_post',
            'subject_id' => $endpointKey,
            'payload' => $payload,
        ]));
        $idempotency = 'article-media-subject-reconcile-' . substr($planFingerprint, 0, 48);
        return array_replace($inspection, [
            'status' => 'PLAN_READY',
            'read_only' => true,
            'mutated' => false,
            'plan_fingerprint' => $planFingerprint,
            'governed_operation' => [
                'tool' => 'nhk.proposal.create',
                'arguments' => [
                    'operation' => 'subject_bind',
                    'entity_type' => 'wp_post',
                    'subject_id' => $endpointKey,
                    'expected_revision' => $inspection['source_revision'] ?? null,
                    'content_fingerprint' => $planFingerprint,
                    'idempotency_key' => $idempotency,
                    'dependency_fingerprint' => $inspection['dependency_fingerprint'],
                    'payload' => $payload,
                ],
            ],
            'apply_requires' => ['proposal', 'submission', 'approval', 'eligibility', 'controlled_apply', 'canonical_readback'],
        ]);
    }

    /** @return ArticleMediaSubjectBindingApplyResult */
    public function apply(Proposal $proposal): ArticleMediaSubjectBindingApplyResult
    {
        if ($proposal->entityType !== 'wp_post' || $proposal->operation !== 'subject_bind') throw new \InvalidArgumentException('ARTICLE_MEDIA_SUBJECT_BINDING_OPERATION_INVALID');
        $payload = $proposal->payload;
        $endpoint = is_array($payload['endpoint'] ?? null) ? $payload['endpoint'] : [];
        $endpointType = strtolower(trim((string) ($endpoint['type'] ?? '')));
        $endpointKey = trim((string) ($endpoint['id'] ?? $proposal->subjectId));
        if ($endpointType !== 'wp_post' || $endpointKey === '' || $endpointKey !== $proposal->subjectId) throw new \InvalidArgumentException('ARTICLE_MEDIA_SUBJECT_BINDING_ENDPOINT_INVALID');

        $plan = $this->plan($endpointType, $endpointKey);
        if (($plan['status'] ?? '') !== 'PLAN_READY') throw new \RuntimeException((string) (($plan['reason'] ?? $plan['status'] ?? 'ARTICLE_MEDIA_SUBJECT_BINDING_UNAVAILABLE')));
        $expectedDependency = trim((string) ($payload['dependency_fingerprint'] ?? ''));
        if ($expectedDependency === '' || !hash_equals($expectedDependency, (string) $plan['dependency_fingerprint'])) throw new \RuntimeException('ARTICLE_MEDIA_SUBJECT_BINDING_DEPENDENCY_CHANGED');
        if ((int) ($proposal->expectedRevision ?? 0) > 0 && (int) ($plan['source_revision'] ?? 0) !== (int) $proposal->expectedRevision) throw new \RuntimeException('ARTICLE_MEDIA_SUBJECT_BINDING_SOURCE_REVISION_CHANGED');
        if ((int) ($payload['expected_source_revision'] ?? 0) !== (int) ($plan['source_revision'] ?? 0)) throw new \RuntimeException('ARTICLE_MEDIA_SUBJECT_BINDING_SOURCE_REVISION_CHANGED');
        if (!$this->sameBlueprintExpectations((array) ($payload['expected_blueprints'] ?? []), (array) ($plan['expected_blueprints'] ?? []))) throw new \RuntimeException('ARTICLE_MEDIA_BLUEPRINT_REVISION_CONFLICT');
        if (!hash_equals((string) $proposal->contentFingerprint, (string) ($plan['plan_fingerprint'] ?? ''))) throw new \RuntimeException('ARTICLE_MEDIA_SUBJECT_BINDING_PLAN_CHANGED');

        $repository = $this->blueprints instanceof ArticleMediaBlueprintCasRepository ? $this->blueprints : throw new \RuntimeException('ARTICLE_MEDIA_SUBJECT_BINDING_CAS_UNAVAILABLE');
        $binding = (array) $plan['subject_binding'];
        $readback = [];
        foreach ((array) ($plan['expected_blueprints'] ?? []) as $slot) {
            if (!is_array($slot)) throw new \RuntimeException('ARTICLE_MEDIA_SUBJECT_BINDING_PLAN_INVALID');
            $blueprint = $this->blueprints->findByPostAndSlot((int) ($slot['post_id'] ?? 0), (string) ($slot['slot'] ?? ''));
            if ($blueprint === null || $blueprint->revision !== (int) ($slot['revision'] ?? 0)) throw new \RuntimeException('ARTICLE_MEDIA_BLUEPRINT_REVISION_CONFLICT');
            $context = array_replace($blueprint->subjectContext, [
                'subject_ids' => [$binding['subject_id']],
                'canonical_subject_ids' => [$binding['subject_id']],
                'subject_id' => $binding['subject_id'],
                'canonical_subject_id' => $binding['subject_id'],
                'subject_type' => $binding['subject_type'],
                'canonical_subject_type' => $binding['subject_type'],
                'subject_revision' => (string) $binding['subject_revision'],
                'canonical_subject_revision' => (string) $binding['subject_revision'],
                'subject_binding' => $binding + [
                    'governance_proposal_id' => $proposal->id,
                    'mutation_result' => 'ARTICLE_MEDIA_SUBJECT_BOUND',
                ],
            ]);
            $saved = $repository->saveExpected($blueprint->withSubjectContext($context), $blueprint->revision);
            $savedContext = $saved->subjectContext;
            $savedBinding = is_array($savedContext['subject_binding'] ?? null) ? $savedContext['subject_binding'] : [];
            if (($savedBinding['subject_id'] ?? '') !== $binding['subject_id'] || ($savedBinding['subject_type'] ?? '') !== $binding['subject_type'] || (int) ($savedBinding['subject_revision'] ?? 0) !== (int) $binding['subject_revision'] || ($savedBinding['dependency_fingerprint'] ?? '') !== $binding['dependency_fingerprint']) throw new \RuntimeException('ARTICLE_MEDIA_SUBJECT_BINDING_READBACK_FAILED');
            $readback[] = ['post_id' => $saved->postId, 'slot' => $saved->slot, 'binding_revision' => $saved->revision, 'subject_binding' => $savedBinding];
        }
        return new ArticleMediaSubjectBindingApplyResult($endpointKey, $binding, $readback, ['mutation_result' => 'ARTICLE_MEDIA_SUBJECT_BOUND', 'proposal_id' => $proposal->id]);
    }

    /** @return array<string,mixed> */
    private function inspect(string $endpointType, string $endpointKey, bool $requireBlueprints): array
    {
        $endpointType = strtolower(trim($endpointType));
        $endpointKey = trim($endpointKey);
        $base = ['endpoint' => ['type' => $endpointType, 'id' => $endpointKey], 'read_only' => true, 'mutated' => false];
        if ($endpointType !== 'wp_post' || $endpointKey === '') return $base + ['status' => 'REVIEW_REQUIRED', 'reason' => 'ARTICLE_MEDIA_SUBJECT_BINDING_ENDPOINT_INVALID'];
        try {
            $edges = $this->graph->findOutgoing(new NodeReference('wp_post', $endpointKey), 'about', 0, 200, false)['items'] ?? [];
        } catch (\Throwable $error) {
            return $base + ['status' => 'BLOCKED', 'reason' => 'GRAPH_SUBJECT_READ_UNAVAILABLE', 'diagnostics' => [trim($error->getMessage()) ?: 'GRAPH_SUBJECT_READ_UNAVAILABLE']];
        }
        $edges = array_values(array_filter((array) $edges, static fn (mixed $edge): bool => $edge instanceof GraphEdge
            && $edge->isActive()
            && $edge->predicate === 'about'
            && $edge->source->reference->endpoint_type === 'wp_post'
            && $edge->source->reference->endpoint_key === $endpointKey));
        if ($edges === []) return $base + ['status' => 'MISSING_SUBJECT_BINDING', 'reason' => 'MISSING_SUBJECT_BINDING', 'subject_binding' => null, 'expected_blueprints' => []];
        if (count($edges) !== 1) return $base + ['status' => 'REVIEW_REQUIRED', 'reason' => 'AMBIGUOUS_ACTIVE_ABOUT_SUBJECT', 'active_about_edges' => array_map($this->edge(...), $edges)];

        $edge = $edges[0];
        $target = $edge->target->reference;
        $subject = $this->authority->findByCanonicalId($target->endpoint_key);
        if (!$subject instanceof AuthorityEntity || $subject->entityType !== $target->endpoint_type || !$subject->active()) return $base + ['status' => 'BLOCKED', 'reason' => 'SUBJECT_INACTIVE_OR_UNRESOLVABLE', 'active_about_edges' => [$this->edge($edge)]];
        $sourceRevision = null;
        if (is_callable($this->revisionReader)) {
            try { $sourceRevision = ($this->revisionReader)(new NodeReference('wp_post', $endpointKey)); } catch (\Throwable) { $sourceRevision = null; }
        }
        if ($sourceRevision === null || (int) $sourceRevision < 1) return $base + ['status' => 'BLOCKED', 'reason' => 'ARTICLE_MEDIA_SOURCE_REVISION_UNAVAILABLE', 'active_about_edges' => [$this->edge($edge)], 'subject_binding' => null];
        $subjectBinding = [
            'source' => 'ACTIVE_GRAPH_ABOUT',
            'endpoint' => ['type' => 'wp_post', 'id' => $endpointKey],
            'relation_uuid' => $edge->edge_uuid,
            'relation_revision' => $edge->revision,
            'subject_type' => $subject->entityType,
            'subject_id' => $subject->canonicalId,
            'subject_revision' => $subject->revision,
            'stable_key' => $subject->stableKey,
            'canonical_name' => $subject->canonicalName,
        ];
        $dependencyFingerprint = hash('sha256', $this->json([
            'endpoint' => ['type' => 'wp_post', 'id' => $endpointKey],
            'source_revision' => $sourceRevision,
            'relation_uuid' => $edge->edge_uuid,
            'relation_revision' => $edge->revision,
            'subject_type' => $subject->entityType,
            'subject_id' => $subject->canonicalId,
            'subject_revision' => $subject->revision,
        ]));
        $subjectBinding['dependency_fingerprint'] = $dependencyFingerprint;
        $blueprints = $this->blueprints->listByPost((int) (preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $endpointKey, $match) === 1 ? $match[1] : 0));
        $expected = [];
        foreach ($blueprints as $blueprint) $expected[] = ['post_id' => $blueprint->postId, 'slot' => $blueprint->slot, 'revision' => $blueprint->revision];
        $result = $base + ['status' => 'READY', 'reason' => null, 'active_about_edges' => [$this->edge($edge)], 'subject_binding' => $subjectBinding, 'subject_revision' => $subject->revision, 'source_revision' => $sourceRevision, 'dependency_fingerprint' => $dependencyFingerprint, 'expected_blueprints' => $expected];
        if ($requireBlueprints && $expected === []) return $base + ['status' => 'BLOCKED', 'reason' => 'ARTICLE_MEDIA_BLUEPRINT_NOT_FOUND', 'subject_binding' => $subjectBinding, 'dependency_fingerprint' => $dependencyFingerprint, 'expected_blueprints' => []];
        return $result;
    }

    private function edge(GraphEdge $edge): array
    {
        return ['relation_uuid' => $edge->edge_uuid, 'relation_revision' => $edge->revision, 'predicate' => $edge->predicate, 'source' => ['type' => $edge->source->reference->endpoint_type, 'id' => $edge->source->reference->endpoint_key], 'target' => ['type' => $edge->target->reference->endpoint_type, 'id' => $edge->target->reference->endpoint_key], 'state' => $edge->isActive() ? 'ACTIVE' : 'RETIRED'];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string,mixed>> $left @param list<array<string,mixed>> $right */
    private function sameBlueprintExpectations(array $left, array $right): bool
    {
        $normalize = static function (array $items): array {
            $result = [];
            foreach ($items as $item) if (is_array($item)) $result[] = [(int) ($item['post_id'] ?? 0), (string) ($item['slot'] ?? ''), (int) ($item['revision'] ?? 0)];
            usort($result, static fn (array $a, array $b): int => $a <=> $b);
            return $result;
        };
        return $normalize($left) === $normalize($right);
    }
}
