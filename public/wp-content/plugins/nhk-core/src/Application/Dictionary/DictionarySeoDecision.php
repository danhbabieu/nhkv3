<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

/** One deterministic SEO decision shared by Dictionary detail and sitemap reads. */
final class DictionarySeoDecision
{
    /** @param list<array<string,mixed>> $senses */
    public function decide(string $url, array $senses): array
    {
        $url = trim($url);
        if ($url === '') return ['state' => 'BLOCKED', 'canonical' => null, 'robots' => 'noindex,follow', 'sitemap' => false, 'indexable' => false, 'reason' => 'MISSING_PUBLIC_IDENTITY'];
        $owners = [];
        $lexicalValue = false;
        foreach ($senses as $sense) {
            if (!is_array($sense)) continue;
            $reference = is_array($sense['semantic_reference'] ?? null) ? $sense['semantic_reference'] : [];
            $referenceStatus = strtoupper(trim((string) ($reference['status'] ?? '')));
            if ($referenceStatus !== '' && $referenceStatus !== 'ABSENT' && !in_array($referenceStatus, ['AVAILABLE', 'AVAILABLE_WITH_ITEMS', 'AVAILABLE_EMPTY', 'PRESENT_VALID'], true)) {
                return ['state' => 'BLOCKED', 'canonical' => $url, 'robots' => 'noindex,follow', 'sitemap' => false, 'indexable' => false, 'reason' => 'SEMANTIC_REFERENCE_INVALID'];
            }
            if (strtoupper(trim((string) ($reference['source'] ?? ''))) === 'MAPPING' && in_array($referenceStatus, ['AVAILABLE', 'AVAILABLE_WITH_ITEMS', 'PRESENT_VALID'], true) && !is_array($sense['canonical_owner'] ?? null)) {
                return ['state' => 'BLOCKED', 'canonical' => $url, 'robots' => 'noindex,follow', 'sitemap' => false, 'indexable' => false, 'reason' => 'CANONICAL_OWNER_UNAVAILABLE'];
            }
            $owner = is_array($sense['canonical_owner'] ?? null) ? $sense['canonical_owner'] : null;
            if ($owner !== null) {
                $key = trim((string) ($owner['type'] ?? '')) . ':' . trim((string) ($owner['id'] ?? $owner['canonical_id'] ?? $owner['url'] ?? ''));
                $owners[$key !== ':' ? $key : 'owner:' . count($owners)] = true;
            }
            if (($sense['lexical_value'] ?? null) === true || (trim((string) ($sense['description'] ?? '')) !== '' && ($sense['description_is_owner_summary'] ?? false) !== true) || count((array) ($sense['labels'] ?? [])) > 1) $lexicalValue = true;
        }
        if ($owners === []) return ['state' => 'INDEXABLE', 'canonical' => $url, 'robots' => 'index,follow', 'sitemap' => true, 'indexable' => true];
        if (count($senses) === 1 && !$lexicalValue) {
            $ownerUrl = $this->ownerUrl($senses[0]);
            if ($ownerUrl === null) return ['state' => 'BLOCKED', 'canonical' => $url, 'robots' => 'noindex,follow', 'sitemap' => false, 'indexable' => false, 'reason' => 'CANONICAL_DESTINATION_INCOMPLETE'];
            return ['state' => 'REDIRECT', 'canonical' => $ownerUrl, 'robots' => 'noindex,follow', 'sitemap' => false, 'indexable' => false];
        }
        return ['state' => 'NOINDEX', 'canonical' => $url, 'robots' => 'noindex,follow', 'sitemap' => false, 'indexable' => false];
    }

    private function ownerUrl(array $sense): ?string
    {
        $owner = is_array($sense['canonical_owner'] ?? null) ? $sense['canonical_owner'] : [];
        $url = trim((string) ($owner['url'] ?? $owner['canonical_url'] ?? $owner['current_path'] ?? ''));
        return $url !== '' ? $url : null;
    }
}
