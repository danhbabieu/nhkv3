<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

use NHK\Core\Domain\Media\MediaSeoStateRegistry;

final class PreferredImageSeoProjection
{
    /** @param list<array<string,mixed>> $candidates @return array<string,mixed> */
    public function project(array $candidates): array
    {
        $eligible = array_values(array_filter($candidates, static fn (array $item): bool => ($item['role'] ?? '') === 'representative'
            && ($item['eligible'] ?? true) === true
            && ($item['active'] ?? true) === true
            && (($item['readiness'] ?? 'ready') === 'ready')
            && ($item['state'] ?? null) !== MediaSeoStateRegistry::MISSING
            && ($item['state'] ?? null) !== MediaSeoStateRegistry::PLACEHOLDER
            && ($item['visibility'] ?? 'PUBLIC') === 'PUBLIC'
            && ($item['placeholder'] ?? false) !== true
            && trim((string) ($item['url'] ?? '')) !== ''));
        usort($eligible, static fn (array $a, array $b): int => ((int) ($a['precedence'] ?? 0)) <=> ((int) ($b['precedence'] ?? 0)));
        $selected = $eligible[0] ?? null;
        if ($selected === null) return ['state' => MediaSeoStateRegistry::MISSING, 'eligible' => false, 'url' => null, 'title' => '', 'alt' => '', 'caption' => '', 'metadata_source' => 'MISSING', 'reasons' => ['REPRESENTATIVE_IMAGE_MISSING']];
        $metadata = $this->metadataFor($selected);
        return array_merge($metadata, ['state' => MediaSeoStateRegistry::COMPLETE, 'eligible' => true, 'url' => $selected['url'], 'reasons' => []]);
    }

    /** @param array<string,mixed> $candidates */
    public function forEndpoint(string $type, string $key, array $candidates = []): array { return $this->project($candidates); }

    /** @param array<string,mixed> $candidate @return array{title:string,alt:string,caption:string,metadata_source:string} */
    private function metadataFor(array $candidate): array
    {
        $values = [];
        $sources = [];
        $sourceRank = ['MEDIA_USAGE' => 0, 'SUBJECT_REPRESENTATIVE' => 1, 'MEDIA_NEUTRAL' => 2, 'WORDPRESS_ATTACHMENT' => 3];
        foreach (['title', 'alt', 'caption'] as $field) {
            $value = trim((string) ($candidate[$field] ?? ''));
            $source = $value !== '' ? 'MEDIA_USAGE' : '';
            if ($value === '') { $value = trim((string) ($candidate['usage_' . $field] ?? '')); $source = $value !== '' ? 'MEDIA_USAGE' : ''; }
            if ($value === '') { $value = trim((string) ($candidate['subject_' . $field] ?? '')); $source = $value !== '' ? 'SUBJECT_REPRESENTATIVE' : ''; }
            if ($value === '') {
                $value = trim((string) ($candidate['media_' . $field] ?? ($field === 'title' || $field === 'alt' ? ($candidate['media_name'] ?? '') : '')));
                $source = $value !== '' ? 'MEDIA_NEUTRAL' : '';
            }
            if ($value === '') { $value = trim((string) ($candidate['attachment_' . $field] ?? '')); $source = $value !== '' ? 'WORDPRESS_ATTACHMENT' : ''; }
            $values[$field] = $value;
            if ($source !== '') $sources[] = $source;
        }
        usort($sources, static fn (string $left, string $right): int => ($sourceRank[$left] ?? 99) <=> ($sourceRank[$right] ?? 99));
        $values['metadata_source'] = $sources[0] ?? 'MISSING';
        return $values;
    }
}
