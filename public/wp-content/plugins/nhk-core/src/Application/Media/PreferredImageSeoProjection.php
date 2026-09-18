<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

final class PreferredImageSeoProjection
{
    /** @param list<array<string,mixed>> $candidates @return array<string,mixed> */
    public function project(array $candidates): array
    {
        $eligible = array_values(array_filter($candidates, static fn (array $item): bool => ($item['role'] ?? '') === 'representative' && ($item['visibility'] ?? 'PUBLIC') === 'PUBLIC' && ($item['placeholder'] ?? false) !== true && trim((string) ($item['url'] ?? '')) !== ''));
        usort($eligible, static fn (array $a, array $b): int => ((int) ($a['precedence'] ?? 0)) <=> ((int) ($b['precedence'] ?? 0)));
        $selected = $eligible[0] ?? null;
        if ($selected === null) return ['eligible' => false, 'url' => null, 'reasons' => ['REPRESENTATIVE_IMAGE_MISSING']];
        return ['eligible' => true, 'url' => $selected['url'], 'alt' => trim((string) ($selected['alt'] ?? '')), 'caption' => trim((string) ($selected['caption'] ?? '')), 'reasons' => []];
    }

    /** @param array<string,mixed> $candidates */
    public function forEndpoint(string $type, string $key, array $candidates = []): array { return $this->project($candidates); }
}
