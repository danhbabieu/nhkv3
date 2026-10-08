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
        self::assertSame('DERIVED', $result['music_dossier']['sections']['clock_application']['items'][0]['origin']['kind']);
        self::assertSame(['configured_with_music', 'variant_of', 'model_of'], $result['music_dossier']['sections']['clock_application']['items'][0]['origin']['predicates']);
        self::assertSame('DIRECT', $result['music_dossier']['sections']['clock_application']['items'][1]['origin']['kind']);
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
        self::assertArrayHasKey('verified_clocks', $sections);
        self::assertArrayNotHasKey('score', $sections);
        self::assertArrayNotHasKey('audio', $sections);
        self::assertArrayNotHasKey('related_melodies', $sections);
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
