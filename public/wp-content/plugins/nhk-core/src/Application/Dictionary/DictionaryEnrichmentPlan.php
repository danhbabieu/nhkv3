<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Domain\Dictionary\{LexicalEntry, LexicalEntryForm};

/** Builds deterministic Dictionary-owned actions from an audit snapshot. */
final class DictionaryEnrichmentPlan
{
    public function __construct(private object $entries, private DictionaryEnrichmentOwnerResolver $resolver) {}

    /** @return array<string,mixed> */
    public function build(array $audit, array $options = []): array
    {
        $actions = [];
        foreach ((array) ($audit['items'] ?? []) as $item) foreach ((array) ($item['senses'] ?? [$item]) as $sense) {
            $owner = (array) ($sense['owner_resolution'] ?? $item['owner_resolution'] ?? []);
            foreach ((array) ($sense['approved_legacy_labels'] ?? $item['approved_legacy_labels'] ?? []) as $label) {
                $labelText = is_array($label) ? trim((string) ($label['label'] ?? '')) : trim((string) $label);
                if ($labelText === '') continue;
                $source = is_array($label) ? strtolower((string) ($label['source'] ?? '')) : '';
                $base = ['entry_id' => $item['entry_id'] ?? '', 'sense_id' => $sense['sense_id'] ?? '', 'current_revision' => (int) ($sense['current_revision'] ?? 0), 'form' => $labelText, 'kind' => (string) (is_array($label) ? ($label['kind'] ?? LexicalEntryForm::ALTERNATE) : LexicalEntryForm::ALTERNATE), 'locale' => is_array($label) ? ($label['locale'] ?? null) : null, 'context' => is_array($label) && is_array($label['context'] ?? null) ? $label['context'] : (is_array($sense['context'] ?? null) ? $sense['context'] : []), 'evidence' => ['approved_durable_label'], 'risk' => 'LEXICAL_ONLY'];
                if (!in_array($source, ['', 'approved', 'legacy', 'curated'], true)) { $actions[] = $base + ['action_type' => 'REVIEW_REQUIRED', 'status' => 'REVIEW_REQUIRED', 'reason' => 'source is not approved durable data']; continue; }
                if (strtoupper($base['kind']) === 'HIDDEN') { $actions[] = $base + ['action_type' => 'REVIEW_REQUIRED', 'status' => 'BLOCKED', 'reason' => 'hidden Form semantics are not supported']; continue; }
                $existing = $this->existingForms((string) ($item['entry_id'] ?? ''));
                $normalized = (new DictionaryTermNormalizer())->normalize($labelText);
                $duplicate = false; foreach ($existing as $form) { $value = is_array($form) ? ($form['form'] ?? '') : ($form->form ?? ''); if ((new DictionaryTermNormalizer())->normalize((string) $value) === $normalized) { $duplicate = true; break; } }
                $actions[] = $base + ['action_type' => $duplicate ? 'NOOP' : 'ADD_ENTRY_FORM', 'status' => $duplicate ? 'NOOP' : 'READY', 'reason' => $duplicate ? 'Form already exists' : 'approved durable label'];
            }
            if (($owner['classification'] ?? '') !== 'EXACT_UNIQUE') $actions[] = ['action_type' => 'REVIEW_REQUIRED', 'status' => 'REVIEW_REQUIRED', 'entry_id' => $item['entry_id'] ?? '', 'sense_id' => $sense['sense_id'] ?? '', 'current_revision' => (int) ($sense['current_revision'] ?? 0), 'target' => $owner['target'] ?? null, 'evidence' => $owner['evidence'] ?? [], 'risk' => 'SEMANTIC_OWNER', 'reason' => $owner['reason'] ?? 'owner resolution is not exact and unique'];
            else {
                $reference = array_key_exists('persisted_semantic_reference', $sense)
                    ? $sense['persisted_semantic_reference']
                    : ($sense['semantic_reference'] ?? $item['persisted_semantic_reference'] ?? $item['semantic_reference'] ?? ['status' => 'ABSENT']);
                $referenceStatus = strtoupper((string) (is_array($reference) ? ($reference['status'] ?? 'ABSENT') : 'ABSENT'));
                if (!in_array($referenceStatus, ['PRESENT_VALID', 'AVAILABLE'], true)) $actions[] = ['action_type' => 'SET_SEMANTIC_REFERENCE', 'status' => $referenceStatus === 'STALE' || $referenceStatus === 'INVALID' ? 'BLOCKED' : 'READY', 'entry_id' => $item['entry_id'] ?? '', 'sense_id' => $sense['sense_id'] ?? '', 'current_revision' => (int) ($sense['current_revision'] ?? 0), 'target' => $owner['target'] ?? null, 'evidence' => $owner['evidence'] ?? [], 'risk' => 'SEMANTIC_REFERENCE', 'reason' => $referenceStatus === 'ABSENT' ? 'exact unique owner reference is absent' : 'existing semantic reference requires review'];
            }
        }
        usort($actions, static fn (array $a, array $b): int => json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) <=> json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $status = $actions === [] ? 'NOOP' : (count(array_filter($actions, static fn (array $a): bool => ($a['status'] ?? '') === 'REVIEW_REQUIRED' || ($a['status'] ?? '') === 'BLOCKED')) > 0 ? 'REVIEW_REQUIRED' : 'READY');
        return ['status' => $status, 'actions' => $actions, 'owner_candidates' => [], 'fingerprint' => $this->fingerprint($actions)];
    }

    public function fingerprint(array $actions): string { return hash('sha256', json_encode($this->sort($actions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }

    private function existingForms(string $entryId): array
    {
        if (!method_exists($this->entries, 'listForms') || !method_exists($this->entries, 'findById')) return [];
        $entry = $this->entries->findById($entryId);
        return $entry instanceof LexicalEntry ? (array) $this->entries->listForms($entry) : [];
    }
    private function sort(mixed $value): mixed { if (!is_array($value)) return $value; if (!array_is_list($value)) ksort($value); foreach ($value as $key => $item) $value[$key] = $this->sort($item); return $value; }
}
