<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Contracts\Knowledge\{EvidenceRepository, SourceRepository};
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Domain\Projection\{ClaimProjectionScope, ProjectedClaim, ProjectionSection};

final class LiveLedgerProjectionBuilder
{
    /** @var array<string,string> */
    private const LABELS = [
        'identity' => 'Nhận diện', 'history' => 'Lịch sử', 'classification' => 'Phân loại',
        'mechanism' => 'Bộ máy', 'configuration' => 'Cấu hình', 'component' => 'Thành phần',
        'dial_and_hands' => 'Mặt số & kim', 'case_and_decoration' => 'Vỏ & trang trí',
        'music_and_strike' => 'Bản nhạc & hệ thống chuông', 'sound' => 'Âm thanh',
        'operation' => 'Vận hành', 'dimension' => 'Kích thước', 'material' => 'Vật liệu',
        'provenance' => 'Nguồn gốc', 'user_experience' => 'Kinh nghiệm sử dụng',
        'identification_rule' => 'Kinh nghiệm nhận diện', 'comparison' => 'So sánh',
        'exception' => 'Ngoại lệ', 'dispute' => 'Điểm còn tranh luận', 'other' => 'Khác',
    ];

    public function __construct(
        private ClaimScopeResolver $resolver,
        private ?ClaimRanker $ranker = null,
        private ?ClaimClusterer $clusterer = null,
        private ?EvidenceRepository $evidence = null,
        private ?SourceRepository $sources = null,
        private ?ProjectionInputHasher $hasher = null,
    ) {}

    /** @return array<string,mixed> */
    public function build(NodeReference $node, array $options = []): array
    {
        $resolved = $this->resolver->resolve($node, (int) ($options['max_distance'] ?? ClaimScopeResolver::MAX_DISTANCE));
        if (($resolved['status'] ?? '') !== 'available') return $resolved + ['projection_revision' => 0, 'generated_at' => gmdate('c')];
        $all = array_map(fn (ProjectedClaim $claim): ProjectedClaim => $this->withEvidence($claim), $resolved['items']);
        $all = ($this->ranker ??= new ClaimRanker())->rank($all);
        $clusters = ($this->clusterer ??= new ClaimClusterer())->cluster($all);
        $sections = [];
        $page = max(1, (int) ($options['page'] ?? 1));
        $perSection = ($options['materialize_all'] ?? false) === true ? 100000 : min(100, max(1, (int) ($options['per_section'] ?? 50)));
        $byCategory = [];
        foreach ($all as $claim) $byCategory[$claim->category][] = $claim;
        foreach ($byCategory as $category => $claims) {
            $total = count($claims);
            $claims = array_slice($claims, ($page - 1) * $perSection, $perSection);
            $section = new ProjectionSection($category, self::LABELS[$category] ?? 'Khác', array_map(static fn (ProjectedClaim $claim): array => $claim->toArray(), $claims), [], null, false);
            $sectionData = $section->toArray(); $sectionData['claim_count'] = $total;
            $sections[] = $sectionData + ['total_count' => $total, 'page' => $page, 'per_page' => $perSection, 'page_count' => (int) ceil($total / $perSection), 'clusters' => array_values(array_map(static fn ($cluster): array => $cluster->toArray(), array_filter($clusters, static fn ($cluster): bool => $cluster->representativeClaim->category === $category)))];
        }
        usort($sections, static fn (array $a, array $b): int => [$a['key'], $a['label']] <=> [$b['key'], $b['label']]);
        $inputHash = ($this->hasher ??= new ProjectionInputHasher())->hash($node->endpoint_key, $all, (int) ($options['policy_revision'] ?? GraphProjectionPolicy::REVISION), (int) ($options['template_revision'] ?? 1));
        $dependencies = [];
        foreach ($all as $claim) {
            $dependencies[] = ['kind' => 'claim', 'id' => $claim->claim->canonicalId, 'section_key' => $claim->category, 'scope' => $claim->scope->scope, 'graph_distance' => $claim->scope->graphDistance, 'dependency_revision' => $claim->claim->revision];
            if ($this->evidence !== null && $this->sources !== null) try {
                foreach ($this->evidence->listByClaim($claim->claim->canonicalId) as $evidence) {
                    $source = $this->sources->findByCanonicalId($evidence->sourceId);
                    if ($source === null) continue;
                    $dependencies[] = ['kind' => 'evidence', 'id' => $evidence->canonicalId, 'section_key' => $claim->category, 'scope' => $claim->scope->scope, 'graph_distance' => $claim->scope->graphDistance, 'dependency_revision' => $evidence->revision];
                    $dependencies[] = ['kind' => 'source', 'id' => $source->canonicalId, 'section_key' => $claim->category, 'scope' => $claim->scope->scope, 'graph_distance' => $claim->scope->graphDistance, 'dependency_revision' => $source->revision];
                }
            } catch (\Throwable $error) { throw new \RuntimeException('PROJECTION_DEPENDENCY_READ_FAILED', 0, $error); }
            foreach ($claim->scope->graphPath as $hop) if (trim((string) ($hop['edge_uuid'] ?? '')) !== '') $dependencies[] = ['kind' => 'relation', 'id' => (string) $hop['edge_uuid'], 'section_key' => $claim->category, 'scope' => $claim->scope->scope, 'graph_distance' => $claim->scope->graphDistance, 'dependency_revision' => (int) ($hop['edge_revision'] ?? 1)];
        }
        $directCount = count(array_filter($all, static fn (ProjectedClaim $claim): bool => $claim->scope->scope === ClaimProjectionScope::DIRECT));
        return [
            'status' => 'available', 'node_uuid' => $node->endpoint_key, 'node_type' => $node->endpoint_type,
            'projection_revision' => (int) ($options['projection_revision'] ?? 1), 'generated_at' => gmdate('c'),
            'input_hash' => $inputHash, 'claim_set_hash' => hash('sha256', implode('|', array_map(static fn (ProjectedClaim $claim): string => $claim->claim->canonicalId . ':' . $claim->claim->revision, $all))),
            'graph_hash' => hash('sha256', json_encode(array_map(static fn (ProjectedClaim $claim): array => $claim->scope->graphPath, $all), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'direct_count' => $directCount, 'related_count' => count($all) - $directCount,
            'claim_count' => count($all), 'sections' => $sections, 'has_more' => array_sum(array_map(static fn (array $section): int => max(0, (int) ($section['total_count'] ?? 0) - ($page * (int) ($section['per_page'] ?? 1))), $sections)) > 0, '_dependencies' => $dependencies,
        ];
    }

    private function withEvidence(ProjectedClaim $claim): ProjectedClaim
    {
        $evidenceCount = 0; $sourceIds = [];
        if ($this->evidence !== null && $this->sources !== null) {
            try {
                foreach ($this->evidence->listByClaim($claim->claim->canonicalId) as $item) {
                    $source = $this->sources->findByCanonicalId($item->sourceId);
                    if ($item->active && $item->isPublic() && $source !== null && $source->active && $source->isPublic()) { $evidenceCount++; $sourceIds[$source->canonicalId] = true; }
                }
            } catch (\Throwable $error) { throw new \RuntimeException('EVIDENCE_UNAVAILABLE', 0, $error); }
        }
        return new ProjectedClaim($claim->claim, $claim->category, $claim->scope, $claim->status, $claim->context, $claim->displayText, ['source_count' => count($sourceIds), 'evidence_count' => $evidenceCount], $claim->score);
    }
}
