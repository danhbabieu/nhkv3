<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

use NHK\Core\Application\PublicIdentity\CanonicalPublicSlugPolicy;
use NHK\Core\Domain\Dictionary\LexicalEntry;

final class DictionaryEntryPublicIdentityWriter
{
    public function __construct(private $slugTaken) {}

    public function assign(LexicalEntry $entry): LexicalEntry
    {
        $requested = trim((string) ($entry->context['public_slug'] ?? ''));
        $base = $requested !== '' ? $requested : $entry->preferredForm;
        $slug = (new CanonicalPublicSlugPolicy())->resolve(
            $base,
            $this->qualifiers($entry->context),
            fn (string $candidate): bool => is_callable($this->slugTaken) && (bool) ($this->slugTaken)($candidate, $entry->entryId),
        );
        if (($entry->context['public_slug'] ?? null) === $slug) return $entry;
        return new LexicalEntry(
            $entry->entryId,
            $entry->preferredForm,
            $entry->normalizedPreferredForm,
            $entry->status,
            $entry->locale,
            array_merge($entry->context, ['public_slug' => $slug]),
            $entry->revision,
            $entry->senseIds,
        );
    }

    /** @return list<string> */
    private function qualifiers(array $context): array
    {
        $out = [];
        foreach (['domain', 'term_type', 'region', 'community', 'usage_scope', 'locale'] as $key) {
            $value = $context[$key] ?? null;
            foreach (is_array($value) ? $value : [$value] as $candidate) {
                if (is_scalar($candidate) && trim((string) $candidate) !== '') $out[] = (string) $candidate;
            }
        }
        return array_values(array_unique($out));
    }
}
