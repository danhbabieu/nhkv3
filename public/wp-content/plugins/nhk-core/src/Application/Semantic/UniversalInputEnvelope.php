<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/**
 * The single transient input contract for shared enrichment.
 *
 * This object carries context without asserting canonical identity, evidence
 * or Knowledge. It is deliberately safe to construct from sparse input.
 */
final readonly class UniversalInputEnvelope
{
    /** @param array<string,mixed> $value */
    private function __construct(private array $value)
    {
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $owner = trim((string) ($input['owner_or_source_type'] ?? $input['input_type'] ?? 'generic'));
        $body = self::scalarString($input['body'] ?? $input['text'] ?? $input['raw_text'] ?? $input['raw_input'] ?? '');
        $subjectResolution = is_array($input['subject_resolution'] ?? null) ? $input['subject_resolution'] : [];
        $observations = self::normalizeRecords($input['observations'] ?? [], 'MACHINE_DERIVED');
        $components = self::normalizeComponents($input['components'] ?? []);
        $confidence = (float) ($input['confidence'] ?? 0.0);
        $diagnostics = [];
        if ($subjectResolution === [] || !is_array($subjectResolution['primary'] ?? null)) {
            $diagnostics[] = 'CANONICAL_SUBJECT_UNAVAILABLE';
        }
        $title = self::scalarString($input['title'] ?? '');
        if ($body === '' && $title === '') {
            $diagnostics[] = 'INPUT_CONTENT_UNAVAILABLE';
        }

        $value = [
            'owner_or_source_type' => $owner !== '' ? strtolower($owner) : 'generic',
            'source_identity' => is_array($input['source_identity'] ?? null) ? $input['source_identity'] : [],
            'title' => $title,
            'body' => $body,
            'raw_text' => $body,
            'subject_resolution' => $subjectResolution,
            'subject_hints' => self::strings($input['subject_hints'] ?? []),
            'observations' => $observations,
            'source_metadata' => is_array($input['source_metadata'] ?? null) ? $input['source_metadata'] : (is_array($input['metadata'] ?? null) ? $input['metadata'] : []),
            'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
            'relations' => self::records($input['relations'] ?? []),
            'existing_knowledge' => self::records($input['existing_knowledge'] ?? []),
            'user_hints' => self::records($input['user_hints'] ?? []),
            'requested_intent' => trim((string) ($input['requested_intent'] ?? $input['content_intent'] ?? '')),
            'content_intent' => trim((string) ($input['content_intent'] ?? $input['requested_intent'] ?? '')),
            'publication_context' => is_array($input['publication_context'] ?? null) ? $input['publication_context'] : [],
            'semantic_context' => is_array($input['semantic_context'] ?? null) ? $input['semantic_context'] : [],
            'provenance' => is_array($input['provenance'] ?? null) ? $input['provenance'] : [],
            'confidence' => max(0.0, min(1.0, $confidence)),
            'constraints' => is_array($input['constraints'] ?? null) ? $input['constraints'] : [],
            'target_surface' => trim((string) ($input['target_surface'] ?? 'generic')) ?: 'generic',
            'components' => $components,
            'diagnostics' => array_values(array_unique(array_merge($diagnostics, self::strings($input['diagnostics'] ?? [])))),
        ];

        return new self($value);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->value;
    }

    /** @param mixed $value @return list<array<string,mixed>> */
    private static function normalizeRecords(mixed $value, string $defaultOrigin): array
    {
        $result = [];
        foreach (self::records($value) as $record) {
            $origin = strtoupper(trim((string) ($record['origin'] ?? '')));
            $record['origin'] = $origin !== '' ? $origin : $defaultOrigin;
            $result[] = $record;
        }
        return $result;
    }

    /** @param mixed $value @return list<array<string,mixed>> */
    private static function normalizeComponents(mixed $value): array
    {
        $result = [];
        foreach (self::records($value) as $record) {
            $origin = strtoupper(trim((string) ($record['origin'] ?? '')));
            if ($origin !== '') {
                $record['origin'] = $origin;
                $result[] = $record;
            }
        }
        return $result;
    }

    /** @param mixed $value @return list<array<string,mixed>> */
    private static function records(mixed $value): array
    {
        return array_values(array_filter((array) $value, static fn (mixed $item): bool => is_array($item)));
    }

    /** @param mixed $value @return list<string> */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item, " \t\n\r\0\x0B"), (array) $value), static fn (string $item): bool => $item !== ''));
    }

    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
