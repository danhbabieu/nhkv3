<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry};

/** Bounded, read-only composition of lexical and owner-backed enrichment state. */
final class DictionaryEnrichmentAudit
{
    public function __construct(private object $entries, private object $concepts, private $coverage, private $ownerResolver, private $relatedProjection = null) {}

    /** @return array<string,mixed> */
    public function audit(int $limit, ?string $cursor = null, ?string $entryId = null, ?string $senseId = null, bool $publicOnly = true): array
    {
        $limit = max(1, min(100, $limit));
        $offset = $this->decodeCursor($cursor);
        $entries = $this->listEntries($limit + 1, $offset);
        $hasMore = count($entries) > $limit;
        if ($hasMore) $entries = array_slice($entries, 0, $limit);
        $items = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof LexicalEntry || ($entryId !== null && $entry->entryId !== $entryId)) continue;
            if ($publicOnly && $entry->status !== DictionaryConcept::APPROVED) continue;
            $senses = method_exists($this->entries, 'listSenses') ? (array) $this->entries->listSenses($entry) : [];
            $sensePackets = [];
            foreach ($senses as $sense) {
                if (!$sense instanceof DictionaryConcept || ($senseId !== null && $sense->conceptId !== $senseId)) continue;
                if ($publicOnly && !$sense->approved()) continue;
                $reference = method_exists($this->entries, 'semanticReference') ? (array) $this->entries->semanticReference($entry->entryId, $sense->conceptId) : ['status' => 'ABSENT'];
                $resolved = is_callable($this->ownerResolver) ? (array) ($this->ownerResolver)($sense, ['semantic_reference' => $reference, 'entry_id' => $entry->entryId]) : (new DictionaryEnrichmentOwnerResolver())->resolve($sense, ['semantic_reference' => $reference]);
                $ownerType = trim((string) ($resolved['target']['type'] ?? $reference['type'] ?? ''));
                $ownerId = trim((string) ($resolved['target']['id'] ?? $reference['id'] ?? ''));
                $coverage = ($ownerType !== '' && $ownerId !== '' && is_callable($this->coverage)) ? (array) ($this->coverage)($ownerType, $ownerId, ['entry_id' => $entry->entryId, 'sense_id' => $sense->conceptId]) : [];
                $forms = method_exists($this->entries, 'listForms') ? (array) $this->entries->listForms($entry) : [];
                $labels = method_exists($this->concepts, 'listLabels') ? (array) $this->concepts->listLabels($sense->conceptId, true) : [];
                $approvedLegacy = array_values(array_filter((array) ($sense->context['approved_legacy_labels'] ?? []), static fn (mixed $label): bool => is_string($label) || (is_array($label) && in_array((string) ($label['source'] ?? 'approved'), ['approved', 'legacy', 'curated'], true))));
                $mentions = (array) ($coverage['mentions'] ?? ['status' => 'UNAVAILABLE', 'count' => 0]);
                $sensePackets[] = ['sense_id' => $sense->conceptId, 'preferred_form' => $sense->preferredLabel, 'definition' => $sense->definition, 'current_revision' => $entry->revision, 'semantic_reference' => $this->reference($reference), 'owner_resolution' => $resolved, 'forms' => $this->forms($forms), 'approved_legacy_labels' => $this->labels($approvedLegacy, $labels), 'coverage' => $coverage, 'mentions' => $mentions, 'related_terms' => $coverage['related_terms'] ?? ['status' => 'UNAVAILABLE', 'count' => 0]];
            }
            if ($sensePackets !== []) $items[] = ['entry_id' => $entry->entryId, 'preferred_form' => $entry->preferredForm, 'forms_count' => count($this->forms(method_exists($this->entries, 'listForms') ? (array) $this->entries->listForms($entry) : [])), 'sense_count' => count($sensePackets), 'semantic_reference' => count($sensePackets) === 1 ? $sensePackets[0]['semantic_reference'] : ['status' => 'AMBIGUOUS'], 'coverage' => count($sensePackets) === 1 ? $sensePackets[0]['coverage'] : [], 'mentions' => count($sensePackets) === 1 ? $sensePackets[0]['mentions'] : ['status' => 'AMBIGUOUS'], 'senses' => $sensePackets, 'classification' => $this->classify($sensePackets), 'next_action' => $this->nextAction($sensePackets)];
        }
        return ['status' => 'AVAILABLE', 'read_only' => true, 'mutated' => false, 'items' => $items, 'next_cursor' => $hasMore ? $this->encodeCursor($offset + $limit) : null, 'has_more' => $hasMore];
    }

    private function listEntries(int $limit, int $offset): array
    {
        if (!method_exists($this->entries, 'listEntries')) return [];
        try {
            $reflection = new \ReflectionMethod($this->entries, 'listEntries');
            $result = $reflection->getNumberOfParameters() >= 2 ? $this->entries->listEntries($limit, $offset) : $this->entries->listEntries($limit + $offset);
            return $offset > 0 && $reflection->getNumberOfParameters() < 2 ? array_slice((array) $result, $offset) : (array) $result;
        } catch (\Throwable) { return []; }
    }

    private function reference(array $reference): array { $status = strtoupper(trim((string) ($reference['status'] ?? 'ABSENT'))); return ['status' => in_array($status, ['AVAILABLE', 'PRESENT_VALID', 'ABSENT', 'STALE', 'INVALID', 'AMBIGUOUS'], true) ? $status : 'INVALID', 'type' => $reference['type'] ?? null, 'id' => $reference['id'] ?? null, 'revision' => $reference['revision'] ?? null, 'source' => $reference['source'] ?? null]; }
    private function forms(array $forms): array
    {
        $mapped = array_map(static fn (mixed $form): ?array => is_object($form)
            ? ['form' => (string) ($form->form ?? ''), 'kind' => (string) ($form->kind ?? 'ALTERNATE'), 'locale' => $form->locale ?? null]
            : (is_array($form) ? ['form' => (string) ($form['form'] ?? ''), 'kind' => (string) ($form['kind'] ?? 'ALTERNATE'), 'locale' => $form['locale'] ?? null] : null), $forms);
        return array_values(array_filter($mapped, static fn (?array $form): bool => $form !== null && trim($form['form']) !== ''));
    }
    private function labels(array $legacy, array $labels): array { $out = $legacy; foreach ($labels as $label) if (is_object($label)) $out[] = ['label' => $label->label, 'kind' => $label->kind, 'locale' => $label->locale, 'source' => 'approved']; elseif (is_array($label)) $out[] = $label; return $out; }
    private function classify(array $senses): string { foreach ($senses as $sense) { $status = $sense['semantic_reference']['status']; if ($status === 'AMBIGUOUS' || $sense['owner_resolution']['classification'] === 'AMBIGUOUS') return 'AMBIGUOUS'; if ($status === 'INVALID' || $status === 'STALE') return 'BLOCKED'; if ($status === 'ABSENT') return 'OWNER_GAP'; foreach ((array) ($sense['coverage'] ?? []) as $packet) if (($packet['status'] ?? '') === 'UNAVAILABLE' || ($packet['status'] ?? '') === 'BLOCKED') return 'OWNER_GAP'; if ($sense['forms'] === [] && $sense['approved_legacy_labels'] !== []) return 'LEXICAL_GAP'; } return 'COMPLETE'; }
    private function nextAction(array $senses): string { $classification = $this->classify($senses); return ['COMPLETE' => 'NONE', 'LEXICAL_GAP' => 'PLAN_FORMS', 'OWNER_GAP' => 'REVIEW_OWNER', 'AMBIGUOUS' => 'REVIEW_REQUIRED', 'BLOCKED' => 'BLOCKED'][$classification] ?? 'REVIEW_REQUIRED'; }
    private function encodeCursor(int $offset): string { return rtrim(strtr(base64_encode((string) $offset), '+/', '-_'), '='); }
    private function decodeCursor(?string $cursor): int { if ($cursor === null || $cursor === '') return 0; $decoded = base64_decode(strtr($cursor, '-_', '+/'), true); return is_string($decoded) && ctype_digit($decoded) ? max(0, (int) $decoded) : 0; }
}
