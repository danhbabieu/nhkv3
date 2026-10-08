<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Domain\Authority\AuthorityEntity;

/** Read-only Music presentation over the established public dossier packet. */
final class MusicDossierProjection
{
    /** @var list<string> */
    private const SECTION_ORDER = [
        'identity', 'audio', 'score', 'introduction', 'history', 'structure',
        'clock_application', 'verified_clocks', 'library', 'research', 'sources', 'related_melodies',
    ];

    public function __construct(private MusicReferenceContract $references = new MusicReferenceContract()) {}

    /** @return array<string,mixed> */
    public function forEntity(AuthorityEntity $entity, array $dossier, ?array $referencePacket = null): array
    {
        if ($entity->entityType !== 'music' || ($dossier['status'] ?? '') !== 'AVAILABLE') return $dossier;

        $reference = $this->reference($referencePacket);
        $sections = [];
        $identity = $this->identity($dossier['identity'] ?? []);
        if ($identity !== []) $sections['identity'] = $identity;

        $knowledge = is_array($dossier['knowledge'] ?? null) ? $dossier['knowledge'] : [];
        $facets = is_array($knowledge['facets'] ?? null) ? $knowledge['facets'] : [];
        $introduction = $this->claims($facets, ['overview', 'summary', 'introduction', 'music']);
        if ($introduction !== []) $sections['introduction'] = ['claims' => $introduction];
        $history = $this->claims($facets, ['history', 'chronology', 'origin', 'authorship']);
        if ($history !== []) $sections['history'] = ['claims' => $history];
        $structure = $this->claims($facets, ['structure', 'technical_configuration', 'music']);
        if ($structure !== []) $sections['structure'] = ['claims' => $structure];

        $relations = is_array($dossier['relation_sections'] ?? null) ? $dossier['relation_sections'] : [];
        $clockApplication = $this->relationItems($relations, ['variants', 'movements']);
        if ($clockApplication !== []) $sections['clock_application'] = ['items' => $clockApplication];
        $verifiedClocks = $this->relationItems($relations, ['brands', 'models', 'variants', 'specimens', 'products']);
        if ($verifiedClocks !== []) $sections['verified_clocks'] = ['items' => $verifiedClocks];

        $library = [
            'media' => $this->safeList($dossier['media_gallery'] ?? []),
            'videos' => $this->safeList($relations['videos'] ?? []),
            'articles' => $this->safeList($relations['articles'] ?? []),
        ];
        $library = array_filter($library, static fn(array $items): bool => $items !== []);
        if ($library !== []) $sections['library'] = $library;

        $dictionary = $this->safeList($dossier['dictionary_terms'] ?? []);
        $research = ['claims' => $this->claims($facets, array_keys($facets))];
        if ($dictionary !== []) $research['dictionary'] = $dictionary;
        if ($research['claims'] !== [] || $dictionary !== []) $sections['research'] = $research;

        $sources = $this->evidence($research['claims']);
        if ($sources !== []) $sections['sources'] = ['evidence' => $sources];

        $relatedMelodies = $this->safeList($relations['related_melodies'] ?? []);
        if ($relatedMelodies !== []) $sections['related_melodies'] = ['items' => $relatedMelodies];
        if ($reference['score'] !== null) $sections['score'] = ['reference' => $reference['score']];
        if ($reference['audio'] !== []) $sections['audio'] = ['items' => $reference['audio']];

        return $dossier + ['music_dossier' => [
            'status' => 'AVAILABLE',
            'profile_key' => 'music-universal-dossier-v1',
            'section_order' => self::SECTION_ORDER,
            'sections' => $sections,
            'score' => $reference['score'],
            'audio' => $reference['audio'],
            'warnings' => [],
        ]];
    }

    /** @return array{score:?array<string,mixed>,audio:list<array<string,mixed>>} */
    private function reference(?array $packet): array
    {
        if ($packet === null) return ['score' => null, 'audio' => []];
        if (!array_key_exists('status', $packet)) $packet = $this->references->normalize($packet);
        if (($packet['status'] ?? '') === 'INVALID') return ['score' => null, 'audio' => []];
        return [
            'score' => is_array($packet['score'] ?? null) ? $this->safeValue($packet['score']) : null,
            'audio' => $this->safeList($packet['audio'] ?? []),
        ];
    }

    /** @return array<string,mixed> */
    private function identity(mixed $value): array
    {
        if (!is_array($value)) return [];
        $result = [];
        foreach (['type', 'name', 'url'] as $key) if (is_scalar($value[$key] ?? null) && trim((string) $value[$key]) !== '') $result[$key] = (string) $value[$key];
        $payload = is_array($value['payload'] ?? null) ? $value['payload'] : [];
        $description = trim((string) ($payload['description'] ?? $payload['summary'] ?? ''));
        if ($description !== '') $result['summary'] = $description;
        return $result;
    }

    /** @param array<string,mixed> $facets @param list<string> $keys @return list<array<string,mixed>> */
    private function claims(array $facets, array $keys): array
    {
        $claims = [];
        foreach ($keys as $key) {
            $items = is_array($facets[$key] ?? null) ? $facets[$key] : [];
            foreach ($items as $item) {
                if (!is_array($item) || trim((string) ($item['text'] ?? '')) === '') continue;
                $claims[] = $this->safeValue($item);
            }
        }
        return $claims;
    }

    /** @param array<string,mixed> $relations @param list<string> $groups @return list<array<string,mixed>> */
    private function relationItems(array $relations, array $groups): array
    {
        $items = [];
        foreach ($groups as $group) foreach ($this->safeList($relations[$group] ?? []) as $item) $items[] = $item;
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function evidence(array $claims): array
    {
        $items = [];
        foreach ($claims as $claim) foreach ($this->safeList($claim['evidence'] ?? []) as $evidence) $items[] = $evidence;
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function safeList(mixed $value): array
    {
        if (!is_array($value)) return [];
        $items = [];
        foreach ($value as $item) if (is_array($item)) $items[] = $this->safeValue($item);
        return array_values(array_filter($items, static fn(array $item): bool => $item !== []));
    }

    /** @return array<string,mixed> */
    private function safeValue(array $value): array
    {
        foreach (['canonical_id', 'stable_key', 'revision', 'state', 'lifecycle', 'metadata', 'private_metadata', 'provenance'] as $key) unset($value[$key]);
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = array_is_list($item) ? $this->safeSequence($item) : $this->safeValue($item);
            elseif (is_object($item)) unset($value[$key]);
        }
        return $value;
    }

    /** @param list<mixed> $items @return list<mixed> */
    private function safeSequence(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (is_array($item)) $result[] = $this->safeValue($item);
            elseif (is_scalar($item)) $result[] = $item;
        }
        return $result;
    }
}
