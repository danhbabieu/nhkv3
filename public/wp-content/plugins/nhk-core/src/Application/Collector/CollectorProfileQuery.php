<?php
declare(strict_types=1);

namespace NHK\Core\Application\Collector;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};

/**
 * Read-only collector projection for one canonical Classification branch.
 * It groups existing Knowledge records; it does not create semantic state.
 */
final class CollectorProfileQuery
{
    /** @var list<string> */
    private const GROUPS = [
        'display_form', 'dimensions', 'dating', 'case_styles', 'motifs',
        'materials', 'craft_modes', 'production_scale', 'movement_family',
        'running_duration', 'drive_system', 'functions', 'sound', 'music',
        'automata', 'night_shutoff', 'condition_guidance', 'originality_guidance',
        'provenance', 'rarity', 'origin_certification',
    ];

    /** @param callable(string,array<string,mixed>):array<string,mixed>|null $relatedReader */
    /** @param callable(string):array{status:string,claims?:list<KnowledgeClaim>,reason?:string}|null $branchClaimReader */
    public function __construct(
        private AuthorityRepository $authority,
        private KnowledgeRepository $claims,
        private EvidenceRepository $evidence,
        private SourceRepository $sources,
        private $relatedReader = null,
        private $branchClaimReader = null,
    ) {}

    /** @return array<string,mixed> */
    public function build(string $classificationId, int $page = 1, int $perPage = 50, int $renderCap = 0): array
    {
        $subject = $this->authority->findByCanonicalId($classificationId);
        if (!$subject instanceof AuthorityEntity || $subject->entityType !== 'classification' || !$subject->active()) {
            return $this->unavailable('CLASSIFICATION_NOT_AVAILABLE');
        }

        $related = $this->related($classificationId);
        if (($related['status'] ?? 'available') !== 'available') return $this->unavailable((string) ($related['reason'] ?? 'BRANCH_RELATION_UNAVAILABLE'));

        $branch = $this->branchClaims($classificationId);
        if (($branch['status'] ?? '') !== 'available') return $this->unavailable((string) ($branch['reason'] ?? 'BRANCH_KNOWLEDGE_UNAVAILABLE'));
        $records = $branch['items'];
        $total = count($records);
        $renderCap = max(0, $renderCap);
        $truncated = $renderCap > 0 && $total > $renderCap;
        $availableRecords = $truncated ? array_slice($records, 0, $renderCap) : $records;
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $pageRecords = array_slice($availableRecords, $offset, $perPage);
        $hasNextPage = ($offset + count($pageRecords)) < count($availableRecords);
        $facets = array_fill_keys(self::GROUPS, []);
        $unresolved = [];
        foreach ($pageRecords as $record) {
            $facet = (string) ($record['facet'] ?? '');
            if (in_array($facet, self::GROUPS, true)) $facets[$facet][] = $record;
            else $unresolved[] = $record;
        }
        foreach ($facets as &$items) usort($items, static fn (array $left, array $right): int => [$left['status'], $left['uuid']] <=> [$right['status'], $right['uuid']]);
        unset($items);

        $media = $this->relatedItems($related, 'media');
        $videos = $this->relatedItems($related, 'videos');
        $articles = $this->relatedItems($related, 'articles');
        $makers = $this->relatedItems($related, 'makers');
        return [
            'status' => 'available',
            'subject' => [
                'type' => 'classification',
                'uuid' => $subject->canonicalId,
                'stable_key' => $subject->stableKey,
                'name' => $subject->canonicalName,
            ],
            'overview' => ['name' => $subject->canonicalName],
            'coverage' => [
                'complete' => !$truncated,
                'truncated' => $truncated,
                'knowledge_count' => $total,
                'knowledge_returned' => count($pageRecords),
                'knowledge_page' => $page,
                'knowledge_per_page' => $perPage,
                'knowledge_has_next_page' => $hasNextPage,
                'media_count' => count($media),
                'media_complete' => $media !== [],
                'video_count' => count($videos),
                'video_complete' => $videos !== [],
            ],
            'facets' => $facets,
            'unresolved' => $unresolved,
            'knowledge' => $pageRecords,
            'related_articles' => $articles,
            'media' => $media,
            'videos' => $videos,
            'makers' => $makers,
        ];
    }

    /** @return array{status:string,items:list<array<string,mixed>>,reason?:string} */
    private function branchClaims(string $subjectId): array
    {
        $relationScoped = is_callable($this->branchClaimReader);
        if ($relationScoped) {
            try {
                $branch = ($this->branchClaimReader)($subjectId);
            } catch (\Throwable) {
                return ['status' => 'unavailable', 'items' => [], 'reason' => 'BRANCH_KNOWLEDGE_UNAVAILABLE'];
            }
            if (!is_array($branch) || ($branch['status'] ?? '') !== 'available') {
                return ['status' => 'unavailable', 'items' => [], 'reason' => (string) ($branch['reason'] ?? 'BRANCH_KNOWLEDGE_UNAVAILABLE')];
            }
            $claims = is_array($branch['claims'] ?? null) ? $branch['claims'] : [];
        } else {
            $claims = $this->claims->list();
        }
        $unique = [];
        foreach ($claims as $claim) {
            if (!$claim instanceof KnowledgeClaim || !$claim->active || !$claim->isPublic()) continue;
            $metadata = $claim->provenance['metadata'] ?? null;
            if (!is_array($metadata)) $metadata = [];
            $metadataSubject = trim((string) ($metadata['subject_id'] ?? ''));
            if ($relationScoped ? ($metadataSubject !== '' && $metadataSubject !== $subjectId) : $metadataSubject !== $subjectId) continue;
            if (isset($unique[$claim->canonicalId])) continue;
            $facet = $this->facet($metadata);
            $evidenceCount = $this->publicEvidenceCount($claim);
            $unique[$claim->canonicalId] = [
                'uuid' => $claim->canonicalId,
                'stable_key' => $claim->stableKey,
                'text' => $claim->claimText,
                'type' => $claim->claimType,
                'facet' => $facet,
                'scope' => (string) ($metadata['scope'] ?? 'entity'),
                'status' => $facet === '' ? 'unresolved' : ($evidenceCount > 0 ? 'verified' : 'partial'),
                'evidence_count' => $evidenceCount,
            ];
        }
        $records = array_values($unique);
        usort($records, static fn (array $left, array $right): int => $left['uuid'] <=> $right['uuid']);
        return ['status' => 'available', 'items' => $records];
    }

    /** @param array<string,mixed> $metadata */
    private function facet(array $metadata): string
    {
        $requested = trim((string) ($metadata['collector_facet'] ?? ''));
        if ($requested !== '') {
            if (!in_array($requested, self::GROUPS, true)) return '';
            if ($requested === 'automata' && !in_array((string) ($metadata['scope'] ?? ''), ['model', 'variant', 'specimen_observation'], true)) return '';
            return $requested;
        }
        return match ((string) ($metadata['facet'] ?? '')) {
            'chronology' => 'dating',
            'movement' => 'movement_family',
            'music' => 'music',
            'provenance' => 'provenance',
            'rarity_frequency' => 'rarity',
            'specimen_observation' => 'condition_guidance',
            default => '',
        };
    }

    /** @return array{status:string,scope?:string,reason?:string,media?:list<array<string,mixed>>,videos?:list<array<string,mixed>>,articles?:list<array<string,mixed>>,makers?:list<array<string,mixed>>} */
    private function related(string $subjectId): array
    {
        if (!is_callable($this->relatedReader)) return ['status' => 'available', 'scope' => 'subject', 'media' => [], 'videos' => [], 'articles' => [], 'makers' => []];
        try {
            $result = ($this->relatedReader)($subjectId, ['subject_uuid' => $subjectId, 'branch_scoped' => true]);
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'reason' => 'BRANCH_RELATION_UNAVAILABLE'];
        }
        if (!is_array($result) || ($result['status'] ?? '') !== 'available') return ['status' => 'unavailable', 'reason' => (string) ($result['reason'] ?? 'BRANCH_RELATION_UNAVAILABLE')];
        if (($result['scope'] ?? 'subject') !== 'subject' || ($result['branch_scoped'] ?? true) !== true) return ['status' => 'unavailable', 'reason' => 'BRANCH_FILTER_UNSUPPORTED'];
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function relatedItems(array $related, string $key): array
    {
        $items = [];
        foreach ((array) ($related[$key] ?? []) as $item) {
            if (!is_array($item)) continue;
            $id = trim((string) ($item['uuid'] ?? $item['id'] ?? $item['canonical_id'] ?? ''));
            if ($id === '') continue;
            $items[$id] = $item + ['uuid' => $id];
        }
        return array_values($items);
    }

    private function publicEvidenceCount(KnowledgeClaim $claim): int
    {
        $count = 0;
        foreach ($this->evidence->listByClaim($claim->canonicalId) as $evidence) {
            if (!$evidence instanceof Evidence || !$evidence->active || !$evidence->isPublic()) continue;
            $source = $this->sources->findByCanonicalId($evidence->sourceId);
            if ($source instanceof Source && $source->active && $source->isPublic()) $count++;
        }
        return $count;
    }

    /** @return array<string,mixed> */
    private function unavailable(string $reason): array
    {
        return ['status' => 'unavailable', 'reason' => $reason, 'facets' => [], 'unresolved' => [], 'knowledge' => [], 'media' => [], 'videos' => [], 'related_articles' => [], 'makers' => []];
    }
}
