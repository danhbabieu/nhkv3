<?php
declare(strict_types=1);
namespace NHK\Core\Application\Knowledge;
final class KnowledgeFragmentProjector
{
    public function project(CurrentTruthPacket $packet, string $fragment): KnowledgeFragmentProjection
    {
        $allowed = ['overview','recognition','configuration','movement','music','history','domestic_cultural','evidence_media','related'];
        if (!in_array($fragment, $allowed, true)) return new KnowledgeFragmentProjection($fragment, '', $this->fingerprint($packet, $fragment), false);
        $content = '';
        if ($packet->claims !== []) $content = implode(' ', array_map(static fn($claim): string => $claim->claimText, $packet->claims));
        if ($packet->contradictions !== []) $content .= ($content !== '' ? ' ' : '') . 'Các tư liệu hiện có ghi nhận nhiều dạng khác nhau; chưa đủ cơ sở để coi một dạng là tuyệt đối.';
        return new KnowledgeFragmentProjection($fragment, trim($content), $this->fingerprint($packet, $fragment));
    }
    private function fingerprint(CurrentTruthPacket $packet, string $fragment): string
    {
        $claims = array_map(static fn($claim): array => [$claim->canonicalId, $claim->revision, $claim->active, $claim->isPublic()], $packet->claims);
        $evidence = $packet->evidenceCoverage['evidence'] ?? [];
        $evidence = array_map(static fn($item): array => [$item->canonicalId, $item->revision, $item->relation, $item->active, $item->isPublic()], $evidence);
        usort($claims, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        usort($evidence, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return hash('sha256', json_encode([$packet->subjectId, $packet->profile->toMetadata(), $fragment, $claims, $evidence, $packet->evidenceCoverage['sources'] ?? [], 'projection-v1', 'deterministic-v1'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
