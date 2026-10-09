<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Entity\MusicCoverageAssessment;
use NHK\Core\Application\Entity\MusicDataCollectionStandard;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class MusicCoverageAssessmentTest extends TestCase
{
    public function test_standard_covers_all_collection_categories_and_field_metadata(): void
    {
        $standard = new MusicDataCollectionStandard();

        self::assertSame(range('A', 'Z'), array_keys($standard->categories()));
        self::assertSame([
            'MISSING', 'UNKNOWN', 'NOT_APPLICABLE', 'DISPUTED', 'BLOCKED',
            'CANDIDATE', 'VERIFIED', 'PUBLIC_READY',
        ], $standard->statuses());
        $applicability = array_values(array_unique(array_column($standard->fields(), 'applicability')));
        sort($applicability);
        self::assertSame(['CONDITIONAL', 'CORE', 'OPTIONAL', 'RECOMMENDED'], $applicability);

        foreach ($standard->fields() as $field) {
            foreach ([
                'field_name', 'display_name_vi', 'purpose', 'applicability',
                'value_type', 'canonical_owner', 'subject_scope',
                'evidence_requirements', 'allowed_source_types',
                'validation_rules', 'public_visibility_rule',
                'related_entity_types', 'westminster_example',
                'missing_data_behavior', 'review_requirement', 'uncertainty_states',
                'duplicate_rule', 'review_rule', 'frontend_consumer',
                'public_display_states', 'example_valid', 'example_invalid',
                'example_missing', 'intake_only',
            ] as $key) {
                self::assertArrayHasKey($key, $field, (string) ($field['field_name'] ?? 'field'));
            }
            self::assertTrue($field['intake_only'], (string) ($field['field_name'] ?? 'field'));
            self::assertIsArray($field['uncertainty_states'], (string) ($field['field_name'] ?? 'field'));
            self::assertNotEmpty($field['uncertainty_states'], (string) ($field['field_name'] ?? 'field'));
            foreach (['duplicate_rule', 'review_rule', 'frontend_consumer', 'example_valid', 'example_invalid', 'example_missing'] as $key) {
                self::assertIsString($field[$key], (string) ($field['field_name'] ?? 'field'));
                self::assertNotSame('', trim($field[$key]), (string) ($field['field_name'] ?? 'field'));
            }
            self::assertIsArray($field['public_display_states'], (string) ($field['field_name'] ?? 'field'));
            self::assertNotEmpty($field['public_display_states'], (string) ($field['field_name'] ?? 'field'));
        }
        self::assertGreaterThanOrEqual(35, count($standard->fields()));
    }

    public function test_empty_music_entity_is_assessed_without_inventing_data(): void
    {
        $assessment = (new MusicCoverageAssessment())->assess($this->entity('Empty Music'), [
            'status' => 'AVAILABLE',
            'identity' => ['type' => 'music', 'name' => 'Empty Music', 'url' => '/ban-nhac/empty-music/'],
            'knowledge' => ['facets' => []],
            'relation_sections' => [],
            'media_gallery' => [],
            'dictionary_terms' => [],
        ]);

        self::assertSame('PARTIAL', $assessment['status']);
        self::assertSame('VERIFIED', $assessment['categories']['A']['status']);
        self::assertSame('MISSING', $assessment['categories']['B']['status']);
        self::assertSame('MISSING', $assessment['categories']['W']['status']);
        self::assertSame('NOT_APPLICABLE', $assessment['categories']['N']['status']);
        self::assertNotSame('', $assessment['next_highest_value_task']['field_name']);
        self::assertSame('Empty Music', $assessment['subject']['name']);
        self::assertArrayNotHasKey('uuid', $assessment['subject']);
        self::assertArrayNotHasKey('stable_key', $assessment['subject']);
    }

    public function test_westminster_shape_distinguishes_verified_candidate_disputed_and_blocked(): void
    {
        $dossier = [
            'status' => 'AVAILABLE',
            'identity' => [
                'type' => 'music',
                'name' => 'Westminster Quarters',
                'url' => '/ban-nhac/westminster/',
                'aliases' => ['Cambridge Quarters'],
                'language' => 'en',
            ],
            'knowledge' => [
                'facets' => [
                    'origin' => [[
                        'text' => 'Origin candidate.',
                        'status' => 'CANDIDATE',
                        'evidence' => [['source_title' => 'Great St Mary’s']],
                    ]],
                    'history' => [[
                        'text' => 'Conflicting pitch assertions.',
                        'status' => 'DISPUTED',
                        'evidence' => [['source_title' => 'UK Parliament']],
                    ]],
                    'structure' => [[
                        'text' => 'Notation witness.',
                        'status' => 'VERIFIED',
                        'evidence' => [['source_title' => 'Grove notation']],
                    ]],
                ],
            ],
            'relation_sections' => [
                'variants' => [['type' => 'variant', 'title' => 'Documented variant', 'url' => '/bien-the/variant/']],
                'movements' => [],
                'videos' => [['type' => 'video', 'title' => 'Bell mechanism', 'url' => '/video/bell/']],
            ],
            'media_gallery' => [['title' => 'Bell diagram', 'image_url' => '/anh/bell.webp']],
            'dictionary_terms' => [['term' => 'Westminster', 'url' => '/tu-dien/westminster/']],
        ];
        $reference = [
            'score' => [
                'version' => 'grove-v1', 'source' => 'Grove notation',
                'verification_status' => 'VERIFIED', 'tuning' => 'A4=440Hz',
                'tempo_bpm' => 72,
                'events' => [['pitch_class' => 'G#', 'octave' => 4, 'start_ms' => 0, 'duration_ms' => 500, 'phrase' => 'Q1']],
            ],
            'audio' => [[
                'mode' => 'BELL_SIMULATION', 'score_version' => 'grove-v1',
                'instrument' => 'Synthetic bell', 'render_method' => 'additive',
                'tuning' => 'A4=440Hz', 'pitch_reference' => 'editorial',
                'tempo_bpm' => 72, 'duration_ms' => 500, 'source' => 'NHK local render',
                'rights' => 'RIGHTS_REVIEW_REQUIRED_BEFORE_GOVERNED_INGEST',
                'verification_status' => 'VERIFIED',
            ]],
        ];

        $assessment = (new MusicCoverageAssessment())->assess($this->entity('Westminster Quarters'), $dossier, $reference);

        self::assertSame('VERIFIED', $assessment['categories']['B']['status']);
        self::assertSame('CANDIDATE', $assessment['categories']['C']['status']);
        self::assertSame('DISPUTED', $assessment['categories']['E']['status']);
        self::assertSame('VERIFIED', $assessment['categories']['H']['status']);
        self::assertSame('VERIFIED', $assessment['categories']['I']['status']);
        self::assertSame('BLOCKED', $assessment['categories']['X']['status']);
        self::assertSame('CANDIDATE', $assessment['categories']['V']['status']);
        self::assertSame('Westminster Quarters', $assessment['subject']['name']);
    }

    public function test_same_assessment_contract_is_used_for_unrelated_music_names(): void
    {
        $service = new MusicCoverageAssessment();
        $westminster = $service->assess($this->entity('Westminster Quarters'), $this->emptyDossier('Westminster Quarters'));
        $aveMaria = $service->assess($this->entity('Ave Maria'), $this->emptyDossier('Ave Maria'));

        self::assertSame(array_keys($westminster['categories']), array_keys($aveMaria['categories']));
        self::assertSame($westminster['next_highest_value_task']['field_name'], $aveMaria['next_highest_value_task']['field_name']);
        self::assertSame('MISSING', $aveMaria['categories']['W']['status']);
    }

    public function test_living_standard_documents_the_runtime_registry_and_workflow_boundaries(): void
    {
        $path = dirname(__DIR__, 6) . '/docs/architecture/MUSIC_DATA_COLLECTION_STANDARD.md';
        $standard = (string) file_get_contents($path);

        self::assertStringContainsString('MusicDataCollectionStandard', $standard);
        self::assertStringContainsString('MusicCoverageAssessment', $standard);
        self::assertStringContainsString('Capture', $standard);
        self::assertStringContainsString('G/G-sharp', $standard);
        self::assertStringContainsString('BELL_SIMULATION', $standard);
        self::assertStringContainsString('MISSING', $standard);
        self::assertStringContainsString('PUBLIC_READY', $standard);
        self::assertStringContainsString('Neither is a historical Big Ben recording.', $standard);
    }

    public function test_reusable_music_dictionary_and_score_audio_templates_are_transient_and_registry_aligned(): void
    {
        $root = dirname(__DIR__, 6);
        $templates = [
            'music' => $root . '/docs/architecture/MUSIC_INPUT_TEMPLATE.md',
            'dictionary' => $root . '/docs/architecture/DICTIONARY_INPUT_TEMPLATE.md',
            'score_audio' => $root . '/docs/architecture/SCORE_AUDIO_INPUT_TEMPLATE.md',
        ];
        foreach ($templates as $name => $path) {
            self::assertFileExists($path, $name);
            $content = (string) file_get_contents($path);
            self::assertStringContainsString('transient', strtolower($content), $name);
            foreach (['MISSING', 'UNKNOWN', 'DISPUTED', 'BLOCKED', 'CANDIDATE', 'VERIFIED', 'PUBLIC_READY'] as $status) {
                self::assertStringContainsString($status, $content, $name . ':' . $status);
            }
        }

        $music = (string) file_get_contents($templates['music']);
        foreach (['MusicDataCollectionStandard', 'A–Z', 'identity resolution', 'claim/source/evidence', 'registered relation', 'missing-data report'] as $term) {
            self::assertStringContainsString($term, $music);
        }
        foreach (['user factual assertion', 'source locator', 'evidence excerpt', 'factual research statement', 'historical hypothesis', 'system inference', 'editorial instruction', 'operational instruction', 'media metadata', 'Dictionary lexical observation', 'score/edition metadata', 'audio/recording metadata'] as $inputClass) {
            self::assertStringContainsString($inputClass, $music);
        }

        $dictionary = (string) file_get_contents($templates['dictionary']);
        foreach (['Entry', 'Form', 'Sense', 'lexical attestation', 'semantic_reference', 'ambig', 'Knowledge', 'Graph'] as $term) {
            self::assertStringContainsString($term, $dictionary);
        }

        $scoreAudio = (string) file_get_contents($templates['score_audio']);
        foreach (['rights', 'checksum', 'HISTORICAL_RECORDING', 'BELL_SIMULATION', 'PIANO_REFERENCE', 'MediaAsset', 'MediaUsage', 'governed delivery'] as $term) {
            self::assertStringContainsString($term, $scoreAudio);
        }
    }

    public function test_existing_media_without_rights_is_blocked_even_without_audio(): void
    {
        $dossier = $this->emptyDossier('Image-only Music');
        $dossier['media_gallery'] = [[
            'title' => 'Unlicensed diagram',
            'image_url' => '/anh/diagram.webp',
            'rights_status' => 'RIGHTS_REVIEW_REQUIRED',
        ]];

        $assessment = (new MusicCoverageAssessment())->assess($this->entity('Image-only Music'), $dossier);

        self::assertSame('BLOCKED', $assessment['categories']['X']['status']);
    }

    private function entity(string $name): AuthorityEntity
    {
        return new AuthorityEntity(UuidCodec::newV7(), 'music', 'nhk:music:' . strtolower(str_replace(' ', '-', $name)), $name, 1, [], AuthorityState::ACTIVE, 1);
    }

    private function emptyDossier(string $name): array
    {
        return [
            'status' => 'AVAILABLE',
            'identity' => ['type' => 'music', 'name' => $name, 'url' => '/ban-nhac/example/'],
            'knowledge' => ['facets' => []],
            'relation_sections' => [],
            'media_gallery' => [],
            'dictionary_terms' => [],
        ];
    }
}
