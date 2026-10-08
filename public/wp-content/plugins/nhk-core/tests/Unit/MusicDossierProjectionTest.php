<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\{MusicDossierProjection, MusicReferenceContract};
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MusicDossierProjectionTest extends TestCase
{
    public function test_westminster_and_sonodo_use_the_same_universal_dossier_shape(): void
    {
        $projection = new MusicDossierProjection();
        $reference = (new MusicReferenceContract())->normalize(['audio' => [$this->audio()]]);

        $westminster = $projection->forEntity($this->entity('Westminster Quarters'), $this->dossier('Westminster Quarters'), $reference);
        $sonodo = $projection->forEntity($this->entity('Sonodo'), $this->dossier('Sonodo'), null);

        self::assertSame('music-universal-dossier-v1', $westminster['music_dossier']['profile_key']);
        self::assertSame($westminster['music_dossier']['profile_key'], $sonodo['music_dossier']['profile_key']);
        self::assertSame($westminster['music_dossier']['section_order'], $sonodo['music_dossier']['section_order']);
        self::assertSame('AVAILABLE', $westminster['music_dossier']['status']);
        self::assertSame('AVAILABLE', $sonodo['music_dossier']['status']);
        self::assertArrayHasKey('audio', $westminster['music_dossier']['sections']);
        self::assertArrayNotHasKey('audio', $sonodo['music_dossier']['sections']);
    }

    public function test_all_music_entities_use_the_v2_section_recipe(): void
    {
        $projection = new MusicDossierProjection();
        $expected = [
            'identity', 'introduction', 'history', 'structure', 'variants',
            'clock_application', 'related_entities', 'score', 'audio', 'library',
            'research', 'sources', 'related_melodies',
        ];

        foreach (['Westminster Quarters', 'Sonodo', 'Ave Maria', 'Empty Music'] as $name) {
            $result = $projection->forEntity($this->entity($name), $this->dossier($name));

            self::assertSame($expected, $result['music_dossier']['section_order'], $name);
        }
    }

    public function test_projection_preserves_scope_and_relation_origin(): void
    {
        $dossier = $this->dossier('Westminster Quarters');
        $dossier['knowledge'] = [
            'status' => 'AVAILABLE',
            'facets' => ['music' => [['text' => 'Direct Music claim.', 'evidence' => [['source_title' => 'International source']]]]],
            'claim_count' => 1,
        ];
        $dossier['relation_sections'] = [
            'variants' => [[
                'type' => 'variant', 'title' => 'Variant context', 'url' => '/bien-the/context/',
                'origin' => ['kind' => 'DERIVED', 'hop_count' => 3, 'predicates' => ['configured_with_music', 'variant_of', 'model_of'], 'via_types' => ['variant', 'model']],
            ]],
            'movements' => [[
                'type' => 'movement', 'title' => 'Movement capability', 'url' => '/bo-may/capability/',
                'origin' => ['kind' => 'DIRECT', 'hop_count' => 1, 'predicates' => ['supports_music'], 'via_types' => []],
            ]],
        ];

        $result = (new MusicDossierProjection())->forEntity($this->entity('Westminster Quarters'), $dossier);

        self::assertSame('Direct Music claim.', $result['music_dossier']['sections']['introduction']['claims'][0]['text']);
        self::assertSame('DERIVED', $result['music_dossier']['sections']['variants']['items'][0]['origin']['kind']);
        self::assertSame(['configured_with_music', 'variant_of', 'model_of'], $result['music_dossier']['sections']['variants']['items'][0]['origin']['predicates']);
        self::assertSame('DIRECT', $result['music_dossier']['sections']['clock_application']['items'][0]['origin']['kind']);
        self::assertArrayNotHasKey('canonical_id', $result['music_dossier']);
        self::assertStringNotContainsString('private-token', json_encode($result['music_dossier'], JSON_THROW_ON_ERROR));
    }

    public function test_projection_omits_empty_sections_without_hiding_existing_relations(): void
    {
        $dossier = $this->dossier('Sonodo');
        $dossier['relation_sections'] = [
            'models' => [['type' => 'model', 'title' => 'Model 24', 'url' => '/mau/model-24/', 'origin' => ['kind' => 'DIRECT', 'hop_count' => 1, 'predicates' => ['model_of'], 'via_types' => []]]],
        ];
        $dossier['dictionary_terms'] = [];

        $result = (new MusicDossierProjection())->forEntity($this->entity('Sonodo'), $dossier);
        $sections = $result['music_dossier']['sections'];

        self::assertArrayHasKey('identity', $sections);
        self::assertArrayHasKey('related_entities', $sections);
        self::assertArrayNotHasKey('score', $sections);
        self::assertArrayNotHasKey('audio', $sections);
        self::assertArrayNotHasKey('related_melodies', $sections);
    }

    public function test_projection_strictly_allowlists_public_claim_and_relation_fields(): void
    {
        $dossier = $this->dossier('Westminster Quarters');
        $dossier['knowledge']['facets']['history'] = [[
            'text' => 'Public history claim.',
            'type' => 'historical',
            'facet' => 'history',
            'scope' => 'music',
            'subject_id' => 'private-subject',
            'claim_id' => 'private-claim',
            'diagnostics' => ['internal' => true],
            'evidence' => [[
                'source_title' => 'Public source',
                'url' => 'https://example.test/source',
                'locator' => 'p. 1',
                'source_id' => 'private-source',
                'metadata' => ['private' => true],
            ]],
        ]];
        $dossier['relation_sections']['models'] = [[
            'type' => 'model',
            'title' => 'Documented model',
            'url' => '/mau/documented-model/',
            'origin' => ['kind' => 'DIRECT', 'hop_count' => 1, 'predicates' => ['model_of'], 'via_types' => ['model']],
            'canonical_id' => 'private-model',
            'stable_key' => 'private-key',
            'diagnostics' => ['hidden' => true],
        ]];

        $result = (new MusicDossierProjection())->forEntity($this->entity('Westminster Quarters'), $dossier);
        $claim = $result['music_dossier']['sections']['history']['claims'][0];
        $evidence = $claim['evidence'][0];
        $relation = $result['music_dossier']['sections']['related_entities']['items'][0];

        self::assertSame('Public history claim.', $claim['text']);
        self::assertArrayNotHasKey('subject_id', $claim);
        self::assertArrayNotHasKey('claim_id', $claim);
        self::assertArrayNotHasKey('diagnostics', $claim);
        self::assertArrayNotHasKey('source_id', $evidence);
        self::assertArrayNotHasKey('metadata', $evidence);
        self::assertArrayNotHasKey('canonical_id', $relation);
        self::assertArrayNotHasKey('stable_key', $relation);
        self::assertArrayNotHasKey('diagnostics', $relation);
    }

    public function test_projection_keeps_product_and_specimen_as_distinct_related_entities(): void
    {
        $dossier = $this->dossier('Westminster Quarters');
        $dossier['relation_sections'] = [
            'variants' => [[
                'type' => 'variant', 'title' => 'Variant context', 'url' => '/bien-the/variant/',
                'origin' => ['kind' => 'DIRECT', 'hop_count' => 1, 'predicates' => ['configured_with_music'], 'via_types' => []],
            ]],
            'specimens' => [['type' => 'specimen', 'title' => 'Physical clock', 'url' => '/hien-vat/clock/']],
            'products' => [['type' => 'product', 'title' => 'Listing offer', 'url' => '/san-pham/listing/']],
        ];

        $result = (new MusicDossierProjection())->forEntity($this->entity('Westminster Quarters'), $dossier);

        self::assertSame('variant', $result['music_dossier']['sections']['variants']['items'][0]['type']);
        self::assertSame(['specimen', 'product'], array_column($result['music_dossier']['sections']['related_entities']['items'], 'type'));
    }

    public function test_projection_keeps_media_video_and_article_library_owners_distinct(): void
    {
        $dossier = $this->dossier('Westminster Quarters');
        $dossier['media_gallery'] = [['image_url' => '/anh/westminster.webp', 'alt' => 'Clock detail']];
        $dossier['relation_sections'] = [
            'videos' => [['type' => 'video', 'title' => 'Bell mechanism', 'url' => '/video/bell-mechanism/']],
            'articles' => [['type' => 'article', 'title' => 'Cambridge Quarters', 'url' => '/goc-chia-se/cambridge-quarters/']],
        ];

        $library = (new MusicDossierProjection())->forEntity($this->entity('Westminster Quarters'), $dossier)['music_dossier']['sections']['library'];

        self::assertSame('/anh/westminster.webp', $library['media'][0]['image_url']);
        self::assertSame('video', $library['videos'][0]['type']);
        self::assertSame('article', $library['articles'][0]['type']);
    }

    public function test_projection_emits_only_governed_audio_delivery_and_keeps_relation_origin_publicly_safe(): void
    {
        $assetId = '018f5b74-5f30-7d2e-9a93-c0e7d6dc3341';
        $audio = $this->audio();
        $audio['media_asset_id'] = $assetId;
        $audio['url'] = 'https://example.invalid/untrusted.mp3';
        $dossier = $this->dossier('Westminster Quarters');
        $dossier['relation_sections'] = [
            'videos' => [[
                'type' => 'video', 'title' => 'Bell mechanism', 'url' => '/video/bell-mechanism/',
                'origin' => ['kind' => 'DERIVED', 'hop_count' => 2, 'predicates' => ['about'], 'via_types' => ['article']],
            ]],
        ];

        $result = (new MusicDossierProjection(
            audioDelivery: static fn (string $id): ?array => $id === $assetId
                ? ['status' => 'AVAILABLE', 'source' => 'MEDIA_ASSET', 'public_url' => '/am-thanh/' . $id . '/']
                : null,
        ))->forEntity($this->entity('Westminster Quarters'), $dossier, ['audio' => [$audio]]);

        $projectedAudio = $result['music_dossier']['audio'][0];
        self::assertSame('/am-thanh/' . $assetId . '/', $projectedAudio['delivery']['public_url']);
        self::assertArrayNotHasKey('media_asset_id', $projectedAudio);
        self::assertArrayNotHasKey('url', $projectedAudio);
        self::assertSame('DERIVED', $result['music_dossier']['sections']['library']['videos'][0]['origin']['kind']);
        self::assertArrayNotHasKey('predicates', $result['music_dossier']['sections']['library']['videos'][0]['origin']);
    }

    public function test_projection_always_normalizes_reference_packets_and_replaces_stale_projection(): void
    {
        $dossier = $this->dossier('Westminster Quarters');
        $dossier['music_dossier'] = ['status' => 'AVAILABLE', 'score' => ['source' => 'stale']];
        $raw = [
            'status' => 'AVAILABLE',
            'score' => [
                'version' => 'v1', 'source' => 'Unverified source', 'verification_status' => 'QUALIFIED',
                'tuning' => 'A4=440Hz', 'tempo_bpm' => 72,
                'events' => [['pitch_class' => 'C', 'octave' => 4, 'start_ms' => 0, 'duration_ms' => 100, 'phrase' => 'p']],
            ],
        ];

        $result = (new MusicDossierProjection())->forEntity($this->entity('Westminster Quarters'), $dossier, $raw);

        self::assertNull($result['music_dossier']['score']);
        self::assertSame('music-universal-dossier-v1', $result['music_dossier']['profile_key']);
    }

    private function dossier(string $name): array
    {
        return [
            'status' => 'AVAILABLE',
            'identity' => ['type' => 'music', 'name' => $name, 'payload' => ['description' => $name . ' overview', 'canonical_id' => 'private-token']],
            'knowledge' => ['status' => 'AVAILABLE', 'facets' => ['history' => [['text' => 'History claim.']], 'music' => []], 'claim_count' => 1],
            'primary_media' => null,
            'media_gallery' => [],
            'relation_sections' => [],
            'warnings' => [],
            'coverage' => [],
        ];
    }

    private function entity(string $name): AuthorityEntity
    {
        return new AuthorityEntity(UuidCodec::newV7(), 'music', 'nhk:music:' . strtolower(str_replace(' ', '-', $name)), $name, 1, ['description' => $name . ' description'], AuthorityState::ACTIVE, 2);
    }

    private function audio(): array
    {
        return [
            'mode' => 'PIANO', 'score_version' => 'score-v1', 'instrument' => 'Piano',
            'render_method' => 'reference', 'tuning' => 'A4=440Hz', 'pitch_reference' => 'concert-pitch',
            'tempo_bpm' => 72, 'duration_ms' => 1000, 'source' => 'licensed source', 'rights' => 'NHK', 'verification_status' => 'VERIFIED',
        ];
    }
}
