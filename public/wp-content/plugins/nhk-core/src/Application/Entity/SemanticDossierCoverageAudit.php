<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeRegistry};

/**
 * Read-only coverage audit for the dossier read model.
 *
 * A reported absence is not a proposal to create a Graph edge, Knowledge
 * claim, MediaUsage, Video attachment or Article relation. The audit only
 * describes what the current governed public projection can and cannot show.
 */
final class SemanticDossierCoverageAudit
{
    /** @var callable(AuthorityEntity): array<string,mixed> */
    private $reader;

    /**
     * @param callable(AuthorityEntity): array<string,mixed> $reader
     */
    public function __construct(
        private EntityTypeRegistry $types,
        private AuthorityRepository $authority,
        callable $reader,
        private bool $reportOptionalContent = true,
    ) {
        $this->reader = $reader;
    }

    /** @return array{summary:array<string,int>,items:list<array<string,mixed>>} */
    public function run(): array
    {
        $items = [];
        $summary = [
            'entity_count' => 0,
            'public_ready_count' => 0,
            'not_public_ready_count' => 0,
            'complete_core_count' => 0,
            'coverage_gap_count' => 0,
        ];

        foreach ($this->types->all() as $definition) {
            foreach ($this->authority->listByType($definition->type) as $entity) {
                if (!$entity instanceof AuthorityEntity || !$entity->active()) continue;
                $summary['entity_count']++;
                $dossier = ($this->reader)($entity);
                if (!is_array($dossier) || ($dossier['status'] ?? '') !== 'AVAILABLE') {
                    $gaps = $this->stringList($dossier['warnings'] ?? []);
                    if ($gaps === []) $gaps[] = 'DOSSIER_UNAVAILABLE';
                    $items[] = $this->row($entity, 'NOT_PUBLIC_READY', $gaps, $dossier);
                    $summary['not_public_ready_count']++;
                    continue;
                }

                $summary['public_ready_count']++;
                $gaps = $this->coverageGaps($dossier);
                $status = $gaps === [] ? 'COMPLETE_CORE' : 'COVERAGE_GAPS';
                if ($status === 'COMPLETE_CORE') $summary['complete_core_count']++;
                else $summary['coverage_gap_count']++;
                $items[] = $this->row($entity, $status, $gaps, $dossier);
            }
        }

        usort($items, static fn(array $a, array $b): int => [(string) $a['type'], (string) $a['stable_key']] <=> [(string) $b['type'], (string) $b['stable_key']]);
        return ['summary' => $summary, 'items' => $items];
    }

    /** @return list<string> */
    private function coverageGaps(array $dossier): array
    {
        $gaps = [];
        $identity = is_array($dossier['identity'] ?? null) ? $dossier['identity'] : [];
        if (trim((string) ($identity['url'] ?? '')) === '') $gaps[] = 'PUBLIC_ROUTE_UNAVAILABLE';

        $availability = is_array($dossier['availability'] ?? null) ? $dossier['availability'] : [];
        if (strtoupper((string) ($availability['graph'] ?? 'UNAVAILABLE')) !== 'AVAILABLE') $gaps[] = 'GRAPH_UNAVAILABLE';
        if (strtoupper((string) ($availability['knowledge'] ?? 'UNAVAILABLE')) === 'UNAVAILABLE') $gaps[] = 'KNOWLEDGE_UNAVAILABLE';

        $coverage = is_array($dossier['coverage'] ?? null) ? $dossier['coverage'] : [];
        if ((int) ($coverage['relation_count'] ?? 0) < 1) $gaps[] = 'GRAPH_COVERAGE_EMPTY';
        if (!is_array($dossier['primary_media'] ?? null) || trim((string) ($dossier['primary_media']['url'] ?? '')) === '') $gaps[] = 'MEDIA_REPRESENTATIVE_ABSENT';

        $knowledge = is_array($dossier['knowledge'] ?? null) ? $dossier['knowledge'] : [];
        $knowledgeCoverage = is_array($knowledge['coverage'] ?? null) ? $knowledge['coverage'] : [];
        if ((int) ($knowledgeCoverage['unsourced_claim_count'] ?? 0) > 0) $gaps[] = 'PUBLIC_EVIDENCE_PARTIAL';

        if ($this->reportOptionalContent) {
            if ((int) ($coverage['video_count'] ?? 0) < 1) $gaps[] = 'VIDEO_COVERAGE_EMPTY';
            if ((int) ($coverage['article_count'] ?? 0) < 1) $gaps[] = 'ARTICLE_COVERAGE_EMPTY';
        }

        foreach ($this->stringList($dossier['warnings'] ?? []) as $warning) {
            if (!in_array($warning, $gaps, true)) $gaps[] = $warning;
        }
        return array_values(array_unique($gaps));
    }

    /** @return array<string,mixed> */
    private function row(AuthorityEntity $entity, string $status, array $gaps, array $dossier): array
    {
        $coverage = is_array($dossier['coverage'] ?? null) ? $dossier['coverage'] : [];
        $knowledge = is_array($dossier['knowledge'] ?? null) ? $dossier['knowledge'] : [];
        $knowledgeCoverage = is_array($knowledge['coverage'] ?? null) ? $knowledge['coverage'] : [];
        return [
            'type' => $entity->entityType,
            'stable_key' => $entity->stableKey,
            'name' => $entity->canonicalName,
            'status' => $status,
            'gaps' => array_values($gaps),
            'public_url' => is_array($dossier['identity'] ?? null) ? ($dossier['identity']['url'] ?? null) : null,
            'relation_count' => (int) ($coverage['relation_count'] ?? 0),
            'knowledge_claim_count' => (int) ($knowledge['claim_count'] ?? 0),
            'public_evidence_count' => (int) ($knowledge['evidence_count'] ?? 0),
            'unsourced_public_claim_count' => (int) ($knowledgeCoverage['unsourced_claim_count'] ?? 0),
            'media_count' => (int) ($coverage['media_count'] ?? 0),
            'video_count' => (int) ($coverage['video_count'] ?? 0),
            'article_count' => (int) ($coverage['article_count'] ?? 0),
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) return [];
        return array_values(array_filter(array_map(static fn(mixed $item): string => is_string($item) ? trim($item) : '', $value), static fn(string $item): bool => $item !== ''));
    }
}
