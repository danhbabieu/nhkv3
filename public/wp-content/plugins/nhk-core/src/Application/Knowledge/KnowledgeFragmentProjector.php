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
        $items = array_map(static fn($claim): array => [$claim->canonicalId, $claim->revision], $packet->claims);
        return hash('sha256', json_encode([$packet->subjectId, $packet->profile->toMetadata(), $fragment, $items, count($packet->qualifiers), count($packet->contradictions)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
