<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Transient shared input packet; it is never canonical semantic storage. */
final readonly class SemanticInputEnvelope
{
    /** @param array<string,mixed> $value */
    private function __construct(private array $value)
    {
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $components = [];
        foreach ((array) ($input['components'] ?? []) as $component) {
            if (!is_array($component)) {
                continue;
            }
            $origin = strtoupper(trim((string) ($component['origin'] ?? '')));
            if ($origin === '') {
                continue;
            }
            $components[] = $component + ['origin' => $origin];
        }

        $observations = [];
        foreach ((array) ($input['observations'] ?? []) as $observation) {
            if (!is_array($observation)) {
                continue;
            }
            $origin = strtoupper(trim((string) ($observation['origin'] ?? '')));
            if ($origin === '') {
                $origin = 'MACHINE_DERIVED';
            }
            $observations[] = $observation + ['origin' => $origin];
        }

        $value = [
            'input_type' => trim((string) ($input['input_type'] ?? 'generic')),
            'raw_text' => trim((string) ($input['raw_text'] ?? $input['text'] ?? '')),
            'title' => trim((string) ($input['title'] ?? '')),
            'subject_resolution' => is_array($input['subject_resolution'] ?? null) ? $input['subject_resolution'] : [],
            'subject_hints' => array_values(array_filter(array_map('strval', (array) ($input['subject_hints'] ?? [])), static fn (string $item): bool => trim($item) !== '')),
            'observations' => $observations,
            'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
            'content_intent' => trim((string) ($input['content_intent'] ?? '')),
            'target_surface' => trim((string) ($input['target_surface'] ?? 'generic')),
            'components' => $components,
        ];

        return new self($value);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->value;
    }
}
