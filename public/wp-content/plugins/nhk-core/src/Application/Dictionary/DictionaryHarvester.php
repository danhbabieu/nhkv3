<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/** Bounded lexical observation adapter; it never owns semantic mutation. */
final class DictionaryHarvester
{
    public function __construct(private object $planning) {}

    /** @param list<array<string,mixed>> $observations */
    public function harvest(array $observations, bool $persist = true): array
    {
        $items = [];
        foreach ($observations as $observation) {
            if (!is_array($observation)) continue;
            $kind = strtoupper(trim((string) ($observation['source_kind'] ?? '')));
            $id = trim((string) ($observation['source_id'] ?? ''));
            $text = trim((string) ($observation['text'] ?? ''));
            if ($kind === '' || $id === '' || $text === '') continue;
            $context = is_array($observation['context'] ?? null) ? $observation['context'] : [];
            $hints = is_array($observation['hints'] ?? null) ? $observation['hints'] : [];
            $plan = $persist
                ? $this->planning->plan($text, $kind, $id, $context, $hints)
                : $this->planning->preview($text, $kind, $id, $context, $hints);
            $items[] = ['source_kind' => $kind, 'source_id' => $id, 'plan' => is_array($plan) ? $plan : [], 'semantic_write' => false, 'persisted' => $persist];
        }
        return ['status' => 'AVAILABLE', 'dry_run' => !$persist, 'items' => $items, 'semantic_mutations' => 0];
    }
}
