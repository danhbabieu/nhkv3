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
        if ($selected === null) return ['state' => 'MISSING', 'eligible' => false, 'url' => null, 'title' => '', 'alt' => '', 'caption' => '', 'metadata_source' => 'MISSING', 'reasons' => ['REPRESENTATIVE_IMAGE_MISSING']];
        $metadata = $this->metadataFor($selected);
        return array_merge($metadata, ['state' => 'COMPLETE', 'eligible' => true, 'url' => $selected['url'], 'reasons' => []]);
    }

    /** @param array<string,mixed> $candidates */
    public function forEndpoint(string $type, string $key, array $candidates = []): array { return $this->project($candidates); }

    /** @param array<string,mixed> $candidate @return array{title:string,alt:string,caption:string,metadata_source:string} */
    private function metadataFor(array $candidate): array
    {
        $values = [];
        foreach (['title', 'alt', 'caption'] as $field) {
            $value = trim((string) ($candidate[$field] ?? ''));
            if ($value === '') $value = trim((string) ($candidate['usage_' . $field] ?? ''));
            if ($value === '') $value = trim((string) ($candidate['subject_' . $field] ?? ''));
            if ($value === '') $value = trim((string) ($candidate['media_' . $field] ?? ($field === 'title' || $field === 'alt' ? ($candidate['media_name'] ?? '') : '')));
            if ($value === '') $value = trim((string) ($candidate['attachment_' . $field] ?? ''));
            $values[$field] = $value;
        }
        $values['metadata_source'] = trim((string) ($candidate['metadata_source'] ?? '')) !== ''
            ? (string) $candidate['metadata_source']
            : ($values['title'] !== '' || $values['alt'] !== '' || $values['caption'] !== '' ? 'MEDIA_NEUTRAL' : 'MISSING');
        return $values;
    }
}
