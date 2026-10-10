<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

use NHK\Core\Domain\Authority\AuthorityEntity;
use NHK\Core\Shared\Uuid\UuidCodec;

/** Read-only Music presentation over the established public dossier packet. */
final class MusicDossierProjection
{
    /** @var list<string> */
    private const SECTION_ORDER = [
        'identity', 'introduction', 'history', 'structure', 'variants',
        'clock_application', 'related_entities', 'score', 'audio', 'library',
        'research', 'sources', 'related_melodies',
    ];

    private ?\Closure $audioDelivery;

    public function __construct(private MusicReferenceContract $references = new MusicReferenceContract(), ?callable $audioDelivery = null)
    {
        $this->audioDelivery = $audioDelivery === null ? null : \Closure::fromCallable($audioDelivery);
    }

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
        $variants = $this->relationItems($relations, ['variants']);
        if ($variants !== []) $sections['variants'] = ['items' => $variants];
        $clockApplication = $this->relationItems($relations, ['movements']);
        if ($clockApplication !== []) $sections['clock_application'] = ['items' => $clockApplication];
        $relatedEntities = $this->relationItems($relations, ['brands', 'models', 'specimens', 'products']);
        if ($relatedEntities !== []) $sections['related_entities'] = ['items' => $relatedEntities];

        $library = [
            'media' => $this->safeList($dossier['media_gallery'] ?? [], 'media'),
            'videos' => $this->safeList($relations['videos'] ?? [], 'video'),
            'articles' => $this->safeList($relations['articles'] ?? [], 'article'),
        ];
        $library = array_filter($library, static fn(array $items): bool => $items !== []);
        if ($library !== []) $sections['library'] = $library;

        $dictionary = $this->safeList($dossier['dictionary_terms'] ?? [], 'dictionary');
        $research = ['claims' => $this->claims($facets, array_keys($facets))];
        if ($dictionary !== []) $research['dictionary'] = $dictionary;
        if ($research['claims'] !== [] || $dictionary !== []) $sections['research'] = $research;

        $sources = $this->evidence($research['claims']);
        if ($sources !== []) $sections['sources'] = ['evidence' => $sources];

        $relatedMelodies = $this->safeList($relations['related_melodies'] ?? [], 'relation');
        if ($relatedMelodies !== []) $sections['related_melodies'] = ['items' => $relatedMelodies];
        if ($reference['score'] !== null) $sections['score'] = ['reference' => $reference['score']];
        if ($reference['audio'] !== []) $sections['audio'] = ['items' => $reference['audio']];

        return array_replace($dossier, ['music_dossier' => [
            'status' => 'AVAILABLE',
            'profile_key' => 'music-universal-dossier-v1',
            'section_order' => self::SECTION_ORDER,
            'sections' => $sections,
            'score' => $reference['score'],
            'audio' => $reference['audio'],
            'warnings' => $reference['warnings'],
        ]]);
    }

    /** @return array{score:?array<string,mixed>,audio:list<array<string,mixed>>,warnings:list<string>} */
    private function reference(?array $packet): array
    {
        if ($packet === null) return ['score' => null, 'audio' => [], 'warnings' => []];
        $packet = $this->references->normalize($packet);
        if (!in_array(($packet['status'] ?? ''), ['AVAILABLE', 'PARTIAL'], true)) return ['score' => null, 'audio' => [], 'warnings' => []];
        return [
            'score' => is_array($packet['score'] ?? null) ? $this->safeScore($packet['score']) : null,
            'audio' => $this->safeList($packet['audio'] ?? [], 'audio'),
            'warnings' => ($packet['errors'] ?? []) === [] ? [] : ['Một số tham chiếu âm nhạc chưa đủ điều kiện hiển thị công khai.'],
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
                $claims[] = $this->safeClaim($item);
            }
        }
        return $claims;
    }

    /** @param array<string,mixed> $relations @param list<string> $groups @return list<array<string,mixed>> */
    private function relationItems(array $relations, array $groups): array
    {
        $items = [];
        foreach ($groups as $group) foreach ($this->safeList($relations[$group] ?? [], 'relation') as $item) $items[] = $item;
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function evidence(array $claims): array
    {
        $items = [];
        foreach ($claims as $claim) foreach ($this->safeList($claim['evidence'] ?? [], 'evidence') as $evidence) $items[] = $evidence;
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function safeList(mixed $value, string $kind = 'relation'): array
    {
        if (!is_array($value)) return [];
        $items = [];
        foreach ($value as $item) if (is_array($item)) {
            $safe = match ($kind) {
                'audio' => $this->safeAudio($item),
                'article' => $this->safeArticle($item),
                'claim' => $this->safeClaim($item),
                'dictionary' => $this->safeDictionary($item),
                'evidence' => $this->safeEvidence($item),
                'media' => $this->safeMedia($item),
                'score' => $this->safeScore($item),
                'video' => $this->safeVideo($item),
                default => $this->safeRelation($item),
            };
            if ($safe !== []) $items[] = $safe;
        }
        return array_values(array_filter($items, static fn(array $item): bool => $item !== []));
    }

    /** @return array<string,mixed> */
    private function safeClaim(array $value): array
    {
        $result = $this->pick($value, ['text', 'type', 'facet', 'scope', 'status', 'excerpt']);
        if (isset($value['evidence'])) $result['evidence'] = $this->safeList($value['evidence'], 'evidence');
        return $result;
    }

    /** @return array<string,mixed> */
    private function safeEvidence(array $value): array
    {
        return $this->pick($value, ['source_title', 'title', 'url', 'locator', 'label', 'excerpt', 'status']);
    }

    /** @return array<string,mixed> */
    private function safeRelation(array $value): array
    {
        $result = $this->pick($value, ['type', 'title', 'name', 'url', 'excerpt', 'summary']);
        if (isset($value['origin']) && is_array($value['origin'])) {
            $origin = [];
            $kind = $value['origin']['kind'] ?? null;
            if (is_string($kind) && in_array($kind, ['DIRECT', 'DERIVED'], true)) $origin['kind'] = $kind;
            $hopCount = $value['origin']['hop_count'] ?? null;
            if (is_int($hopCount) && $hopCount >= 0) $origin['hop_count'] = $hopCount;
            foreach (['predicates', 'via_types'] as $field) {
                $items = $this->scalarList($value['origin'][$field] ?? []);
                if ($items !== []) $origin[$field] = $items;
            }
            if ($origin !== []) $result['origin'] = $origin;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function safeMedia(array $value): array
    {
        return $this->pick($value, ['title', 'image_url', 'thumbnail_url', 'alt', 'summary', 'article_url', 'srcset', 'sizes', 'width', 'height', 'has_real_image']);
    }

    /** @return array<string,mixed> */
    private function safeVideo(array $value): array
    {
        return $this->withOrigin($this->pick($value, ['type', 'title', 'name', 'url', 'excerpt', 'summary']), $value);
    }

    /** @return array<string,mixed> */
    private function safeArticle(array $value): array
    {
        return $this->withOrigin($this->pick($value, ['type', 'title', 'name', 'url', 'excerpt', 'summary', 'date']), $value);
    }

    /** @return array<string,mixed> */
    private function safeDictionary(array $value): array
    {
        return $this->pick($value, ['term', 'label', 'definition', 'part_of_speech', 'url']);
    }

    /** @return array<string,mixed> */
    private function safeAudio(array $value): array
    {
        $result = $this->pick($value, ['mode', 'label', 'score_version', 'instrument', 'render_method', 'tuning', 'pitch_reference', 'tempo_bpm', 'duration_ms', 'source', 'rights', 'verification_status']);
        $assetId = is_string($value['media_asset_id'] ?? null) ? trim($value['media_asset_id']) : '';
        if ($assetId !== '' && UuidCodec::isValid($assetId) && $this->audioDelivery !== null) {
            $delivery = ($this->audioDelivery)($assetId);
            if (is_array($delivery) && ($delivery['status'] ?? '') === 'AVAILABLE' && ($delivery['source'] ?? '') === 'MEDIA_ASSET') {
                $url = is_string($delivery['public_url'] ?? null) ? trim($delivery['public_url']) : '';
                if (preg_match('#^/am-thanh/[0-9a-f-]{36}/$#i', $url) === 1) {
                    $result['delivery'] = $this->pick($delivery, ['status', 'source', 'public_url', 'mime_type']);
                }
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $value @return array<string,mixed> */
    private function withOrigin(array $result, array $value): array
    {
        if (isset($value['origin']) && is_array($value['origin'])) {
            $origin = [];
            $kind = $value['origin']['kind'] ?? null;
            if (is_string($kind) && in_array($kind, ['DIRECT', 'DERIVED'], true)) $origin['kind'] = $kind;
            if ($origin !== []) $result['origin'] = $origin;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function safeScore(array $value): array
    {
        $result = $this->pick($value, ['version', 'edition', 'arrangement_identity', 'source', 'verification_status', 'tuning', 'tempo_bpm']);
        if (is_array($value['playback'] ?? null)) {
            $playback = $this->pick($value['playback'], ['verification_status', 'rights', 'method']);
            $instruments = is_array($value['playback']['instruments'] ?? null) ? array_values(array_intersect($value['playback']['instruments'], ['PIANO', 'BELL', 'GONG'])) : [];
            if (($playback['verification_status'] ?? '') === 'VERIFIED' && ($playback['rights'] ?? '') !== '' && ($playback['method'] ?? '') !== '' && $instruments !== []) {
                $playback['instruments'] = $instruments;
                $result['playback'] = $playback;
            }
        }
        $result['events'] = [];
        foreach (is_array($value['events'] ?? null) ? $value['events'] : [] as $event) {
            if (!is_array($event)) continue;
            $safe = $this->pick($event, ['pitch_class', 'octave', 'start_ms', 'duration_ms', 'phrase', 'rest']);
            if ($safe !== []) $result['events'][] = $safe;
        }
        $result['segments'] = [];
        foreach (is_array($value['segments'] ?? null) ? $value['segments'] : [] as $segment) {
            if (!is_array($segment)) continue;
            $safe = $this->pick($segment, ['key', 'label', 'start_ms', 'end_ms']);
            if ($safe !== []) $result['segments'][] = $safe;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function pick(array $value, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $item = $value[$field] ?? null;
            if (is_scalar($item) && trim((string) $item) !== '') $result[$field] = $item;
            elseif (is_bool($item) || is_int($item) || is_float($item)) $result[$field] = $item;
        }
        return $result;
    }

    /** @return list<string> */
    private function scalarList(mixed $value): array
    {
        if (!is_array($value)) return [];
        return array_values(array_filter(array_map(static fn(mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value), static fn(string $item): bool => $item !== ''));
    }
}
