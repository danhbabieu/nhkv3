<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

/**
 * Read-only intake vocabulary for Music research and coverage.
 *
 * These are collection questions, not persisted Authority fields or a new
 * semantic owner. Canonical values remain owned by the existing boundaries.
 */
final class MusicDataCollectionStandard
{
    /** @var list<string> */
    private const STATUSES = [
        'MISSING', 'UNKNOWN', 'NOT_APPLICABLE', 'DISPUTED', 'BLOCKED',
        'CANDIDATE', 'VERIFIED', 'PUBLIC_READY',
    ];

    /** @return array<string,array<string,mixed>> */
    public function categories(): array
    {
        $categories = [];
        foreach ($this->fieldDefinitions() as $letter => $definition) {
            $categories[$letter] = [
                'category_key' => $letter,
                'display_name_vi' => $definition['display_name_vi'],
                'applicability' => $definition['applicability'],
                'field_names' => array_values(array_column($definition['fields'], 'field_name')),
            ];
        }
        return $categories;
    }

    /** @return list<array<string,mixed>> */
    public function fields(): array
    {
        $fields = [];
        foreach ($this->fieldDefinitions() as $letter => $category) {
            foreach ($category['fields'] as $field) {
                $fields[] = [
                    'field_name' => $field['field_name'],
                    'display_name_vi' => $field['display_name_vi'],
                    'purpose' => $field['purpose'],
                    'applicability' => ($field['applicability'] ?? '') !== '' ? $field['applicability'] : $category['applicability'],
                    'value_type' => $field['value_type'],
                    'canonical_owner' => $field['canonical_owner'],
                    'subject_scope' => $field['subject_scope'],
                    'evidence_requirements' => $field['evidence_requirements'],
                    'allowed_source_types' => $field['allowed_source_types'],
                    'validation_rules' => $field['validation_rules'],
                    'public_visibility_rule' => $field['public_visibility_rule'],
                    'related_entity_types' => $field['related_entity_types'],
                    'westminster_example' => $field['westminster_example'],
                    'missing_data_behavior' => $field['missing_data_behavior'],
                    'review_requirement' => $field['review_requirement'],
                    'uncertainty_states' => $field['uncertainty_states'],
                    'duplicate_rule' => $field['duplicate_rule'],
                    'review_rule' => $field['review_rule'],
                    'frontend_consumer' => $field['frontend_consumer'],
                    'public_display_states' => $field['public_display_states'],
                    'example_valid' => $field['example_valid'],
                    'example_invalid' => $field['example_invalid'],
                    'example_missing' => $field['example_missing'],
                    'category_key' => $letter,
                    'intake_only' => true,
                ];
            }
        }
        return $fields;
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return self::STATUSES;
    }

    /** @return array<string,array<string,mixed>> */
    private function fieldDefinitions(): array
    {
        $commonSources = ['primary_institution', 'scholarly_publication', 'archive_or_catalogue', 'first_party_record', 'governed_observation'];
        $commonValidation = 'Keep the value subject-scoped, source-linked and distinct from unresolved or disputed assertions.';
        $definitions = [
            'A' => ['Định danh bản nhạc', 'CORE', [
                $this->field('canonical_name', 'Tên định danh chuẩn', 'Canonical Music/Authority', 'Music entity', 'Tên chuẩn của bản nhạc; không tự tạo identity mới.', 'Westminster Quarters', $commonSources, 'string'),
                $this->field('identity_scope', 'Phạm vi định danh', 'Authority', 'Music identity', 'Xác định đây là melody/work, không phải Product hoặc Specimen.', 'Melody identity; Westminster use is a separate scope.', $commonSources, 'enum'),
            ]],
            'B' => ['Tên và bí danh', 'CORE', [
                $this->field('preferred_title', 'Tên hiển thị ưu tiên', 'Authority/Public Identity', 'Music identity', 'Tên hiển thị sau khi canonical owner xác nhận.', 'Westminster Quarters', $commonSources, 'string'),
                $this->field('alias', 'Tên gọi khác', 'Authority/Dictionary', 'Music identity', 'Lưu alias có nguồn; không đồng nhất alias với identity mới.', 'Cambridge Quarters; Westminster Chimes', $commonSources, 'list<string>'),
                $this->field('title_language', 'Ngôn ngữ tên', 'Authority/Dictionary', 'Music identity', 'Ghi ngôn ngữ hoặc để UNKNOWN; không suy đoán từ tên.', 'English', $commonSources, 'BCP-47/string', 'OPTIONAL'),
            ]],
            'C' => ['Nguồn gốc và địa lý', 'CORE', [
                $this->field('origin_place', 'Nơi khởi nguồn', 'Knowledge + Source/Evidence', 'Music history', 'Ghi địa điểm theo claim có chứng cứ.', 'Great St Mary’s, Cambridge', $commonSources, 'string'),
                $this->field('origin_date', 'Mốc thời gian khởi nguồn', 'Knowledge + Source/Evidence', 'Music history', 'Phân biệt ngày sáng tác với ngày sử dụng/nhận diện.', '1793 is a source-scoped candidate', $commonSources, 'date/year/claim'),
                $this->field('origin_statement', 'Mệnh đề nguồn gốc', 'Knowledge + Source/Evidence', 'Music history', 'Lưu nguyên mệnh đề đã atomize để tránh nén nhiều thời điểm vào một fact.', 'Cambridge origin statement remains source-scoped', $commonSources, 'claim'),
            ]],
            'D' => ['Nhạc sĩ và quy thuộc', 'RECOMMENDED', [
                $this->field('composer_attribution', 'Quy thuộc tác giả', 'Knowledge + Source/Evidence', 'Music work', 'Tách attribution khỏi truyền thuyết hoặc suy luận.', 'No settled composer attribution in current Westminster packet', $commonSources, 'claim'),
                $this->field('attribution_status', 'Trạng thái quy thuộc', 'Knowledge', 'Music work', 'Ghi VERIFIED, CANDIDATE hoặc DISPUTED theo claim.', 'UNKNOWN until sourced', $commonSources, 'controlled status'),
            ]],
            'E' => ['Dòng thời gian lịch sử', 'CORE', [
                $this->field('timeline_event', 'Sự kiện lịch sử', 'Knowledge + Source/Evidence', 'Music history', 'Atomize composition, adoption, installation, performance and dissemination.', '1859/60 installation context must remain source-scoped', $commonSources, 'dated claim list'),
            ]],
            'F' => ['Mục đích và bối cảnh văn hóa', 'RECOMMENDED', [
                $this->field('cultural_context', 'Bối cảnh văn hóa', 'Knowledge + Source/Evidence', 'Music history', 'Mô tả purpose/use only when supported.', 'Quarter-hour public clock context', $commonSources, 'claim'),
            ]],
            'G' => ['Hình thức và cấu trúc', 'CORE', [
                $this->field('form_description', 'Mô tả hình thức', 'Knowledge + Source/Evidence', 'Music work', 'Mô tả phrase/form, không biến arrangement thành identity.', 'Q1–Q4 groups and hour event in a notation witness', $commonSources, 'claim'),
                $this->field('phrase_sequence', 'Trình tự câu nhạc', 'Score edition + Knowledge', 'Score/notation witness', 'Giữ theo edition và ghi variant/uncertainty.', 'Grove phrase sequence candidate', $commonSources, 'ordered sequence'),
            ]],
            'H' => ['Nhân chứng ký âm', 'CORE', [
                $this->field('notation_witness', 'Nhân chứng ký âm', 'Source/Evidence + Score edition', 'Specific notation witness', 'Lưu edition/page/figure locator; không tự thành canonical score.', 'Grove notation witness', ['scholarly_publication', 'archive_or_catalogue'], 'edition locator'),
            ]],
            'I' => ['Ấn bản bản nhạc', 'RECOMMENDED', [
                $this->field('score_edition', 'Ấn bản bản nhạc', 'Score presentation contract', 'Score edition', 'Xác định version, provenance, verification and rights.', 'grove-cambridge-quarters-d-major-v1', $commonSources, 'versioned record'),
            ]],
            'J' => ['Biên soạn và chuyển giọng', 'CONDITIONAL', [
                $this->field('arrangement_variant', 'Biên soạn/biến thể', 'Knowledge + Score edition', 'Arrangement', 'Tách original, transcription, arrangement and transposition.', 'D-major notation witness; not a settled Westminster public score', $commonSources, 'scoped claim'),
            ]],
            'K' => ['Nốt, nhịp, tempo và tuning', 'CORE', [
                $this->field('pitch_assertion', 'Khẳng định cao độ', 'Knowledge + Score edition', 'Score event/edition', 'Ghi pitch class/octave/tuning theo source; giữ G/G-sharp conflict.', 'Parliament pages contain conflicting G/G-sharp assertions', $commonSources, 'source-scoped assertion'),
                $this->field('rhythm_tempo_tuning', 'Nhịp, tempo, tuning', 'Score edition + performance reference', 'Score/performance assumption', 'Không suy ra absolute playback từ pitch class đơn lẻ.', 'Tempo and tuning remain unresolved for public Westminster score', $commonSources, 'structured metadata'),
            ]],
            'L' => ['Tham chiếu Piano', 'CONDITIONAL', [
                $this->field('piano_reference', 'Bản phát Piano', 'Media/MediaAsset + Score edition', 'Generated/reference audio', 'Chỉ là piano interpretation của score đã đủ điều kiện.', 'Local Piano WAV is non-public research output', ['governed_media_record', 'original_render'], 'audio packet'),
            ]],
            'M' => ['Tham chiếu Bell/Chime', 'CONDITIONAL', [
                $this->field('bell_reference', 'Bản mô phỏng chuông', 'Media/MediaAsset + Score edition', 'Generated/reference audio', 'Luôn ghi rõ BELL_SIMULATION; không gọi là Big Ben historical sound.', 'Local Bell WAV is synthetic simulation', ['governed_media_record', 'original_render'], 'audio packet'),
            ]],
            'N' => ['Bản ghi âm xác thực', 'CONDITIONAL', [
                $this->field('historical_recording', 'Bản ghi lịch sử', 'Media/MediaAsset + Source/Evidence', 'Specific recording', 'Cần chain of custody, checksum, authenticity and rights.', 'No historical recording accepted', ['governed_media_record', 'rights_document'], 'recording packet'),
            ]],
            'O' => ['Cơ chế đồng hồ', 'CONDITIONAL', [
                $this->field('clock_mechanism_context', 'Bối cảnh cơ chế', 'Knowledge + Graph + Source/Evidence', 'Specific clock/mechanism context', 'Không biến Westminster mechanism thành capability của mọi clock.', 'Four quarter bells and Great Bell in Parliament context', $commonSources, 'scoped claim'),
            ]],
            'P' => ['Thương hiệu/mẫu/biến thể đồng hồ', 'CONDITIONAL', [
                $this->field('clock_model_compatibility', 'Tương thích mẫu đồng hồ', 'Graph + Knowledge + Source/Evidence', 'Brand/Model/Variant/Movement', 'Mỗi relation cần subject, predicate and evidence riêng.', 'A configured Variant is not a Brand-wide claim', ['first_party_record', 'archive_or_catalogue', 'governed_relation'], 'relation/context'),
            ]],
            'Q' => ['Hiện vật được ghi nhận', 'CONDITIONAL', [
                $this->field('documented_specimen', 'Hiện vật cụ thể', 'Authority/Specimen + Knowledge/Evidence', 'One physical specimen', 'Specimen luôn khác Product/listing và không mở rộng scope.', 'No exact Westminster specimen in current packet', ['observed_from_media', 'archive_or_catalogue'], 'specimen-scoped claim'),
            ]],
            'R' => ['Hình ảnh và sơ đồ', 'RECOMMENDED', [
                $this->field('image_diagram', 'Hình ảnh/sơ đồ', 'Media/MediaAsset/MediaUsage', 'Media asset and usage', 'Cần exact subject/use and public eligibility; không tự là Evidence.', 'Bell/mechanism diagram if owner-backed', ['governed_media_record', 'first_party_record'], 'media reference'),
            ]],
            'S' => ['Video', 'RECOMMENDED', [
                $this->field('video_reference', 'Video tham chiếu', 'Video + Graph/Source', 'Video owner', 'Giữ Video owner và about target; không coi video là claim tự động.', 'Parliament mechanism video candidate', ['first_party_record', 'governed_video_record'], 'owner reference'),
            ]],
            'T' => ['Catalogue và lưu trữ', 'RECOMMENDED', [
                $this->field('catalogue_archive', 'Catalogue/lưu trữ', 'Source/Evidence', 'Source/archive record', 'Lưu locator, edition and access/rights posture.', 'Starmer scan and Grove access layer', ['archive_or_catalogue', 'scholarly_publication'], 'source locator'),
            ]],
            'U' => ['Knowledge và Dictionary', 'CORE', [
                $this->field('knowledge_dictionary_entry', 'Claim và thuật ngữ', 'Knowledge/Dictionary', 'Subject-scoped claim and lexical projection', 'Knowledge owns fact; Dictionary owns lexical curation.', 'Westminster/ Cambridge Quarters terminology', ['governed_knowledge_record', 'dictionary_projection'], 'claim/term'),
            ]],
            'V' => ['Quan hệ Graph', 'CORE', [
                $this->field('registered_relation', 'Quan hệ đã đăng ký', 'Graph', 'Registered endpoints and predicates', 'Chỉ dùng predicate/endpoint đã đăng ký; no convenience edge.', 'configured_with_music or supports_music only where canonical', ['governed_relation', 'source_evidence'], 'typed relation'),
            ]],
            'W' => ['Nguồn và bằng chứng', 'CORE', [
                $this->field('source_evidence_bundle', 'Gói nguồn/bằng chứng', 'Source/Evidence', 'Claim/source/evidence scope', 'Atomize claims and preserve support/conflict.', 'Great St Mary’s, Parliament, Grove, Starmer', $commonSources, 'source/evidence references'),
                $this->field('evidence_locator', 'Định vị bằng chứng', 'Evidence', 'Claim/source/evidence scope', 'Giữ page, section, figure, timestamp hoặc locator tương đương.', 'Parliament page section or Starmer page 8–9', $commonSources, 'locator'),
            ]],
            'X' => ['Quyền và licensing', 'CORE', [
                $this->field('rights_license', 'Quyền sử dụng', 'Media/Source/Governance', 'Exact asset/source/use', 'No public delivery without an evaluated rights state.', 'Parliament audio requires exact licence review', ['rights_document', 'governed_media_record'], 'rights decision'),
            ]],
            'Y' => ['SEO và trình bày public', 'CORE', [
                $this->field('public_presentation', 'Trình bày công khai', 'Public Projection/WordPress/SEO', 'Public Music route', 'Use persisted public identity and public-safe projection only.', '/ban-nhac/westminster/ when owner-backed', ['public_projection', 'seo_projection'], 'public read model'),
            ]],
            'Z' => ['Review, coverage và nghiên cứu tiếp', 'CORE', [
                $this->field('review_coverage', 'Đánh giá độ phủ', 'Editorial/Governance diagnostics', 'Music dossier assessment', 'Separate complete, verified-not-public, gaps, rights and next task.', 'Westminster next task is source/score/rights review, not invented data', ['editorial_review', 'governed_review'], 'coverage assessment'),
            ]],
        ];
        $normalized = [];
        foreach ($definitions as $letter => $definition) {
            $normalized[$letter] = [
                'display_name_vi' => $definition[0],
                'applicability' => $definition[1],
                'fields' => $definition[2],
            ];
        }
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function field(string $name, string $label, string $owner, string $scope, string $purpose, string $example, array $sources, string $type, string $applicability = ''): array
    {
        return [
            'field_name' => $name,
            'display_name_vi' => $label,
            'purpose' => $purpose,
            'applicability' => $applicability,
            'value_type' => $type,
            'canonical_owner' => $owner,
            'subject_scope' => $scope,
            'evidence_requirements' => 'Atomic proposition or asset reference with source/locator, scope and uncertainty retained.',
            'allowed_source_types' => $sources,
            'validation_rules' => 'Non-empty only when supported; preserve controlled status and reject guessed values.',
            'public_visibility_rule' => 'Public only through the owning public-safe projection and eligibility policy.',
            'related_entity_types' => ['music', 'brand', 'model', 'variant', 'movement', 'specimen', 'product', 'media', 'video', 'knowledge', 'source', 'evidence'],
            'westminster_example' => $example,
            'missing_data_behavior' => 'Keep MISSING, UNKNOWN, NOT_APPLICABLE or BLOCKED explicit; never fabricate a value.',
            'review_requirement' => 'Research/editorial review; governed apply is required for canonical mutation.',
            'uncertainty_states' => self::STATUSES,
            'duplicate_rule' => $this->duplicateRule($name, $owner),
            'review_rule' => $this->reviewRule($name, $owner),
            'frontend_consumer' => $this->frontendConsumer($name, $owner),
            'public_display_states' => [
                'CANONICAL_DATA_AVAILABLE', 'PUBLIC_ELIGIBILITY_REQUIRED', 'RENDERED_FRONTEND_DATA',
                'MISSING_FEATURE', 'MISSING_EVIDENCE', 'MISSING_RIGHTS', 'TEMPORARILY_UNAVAILABLE',
            ],
            'example_valid' => $example,
            'example_invalid' => 'Unscoped, guessed or unsupported input without a source/locator is rejected.',
            'example_missing' => 'MISSING; do not infer a value from a title, alias, URL, file name or related entity.',
            'applicability_override' => $applicability,
        ];
    }

    private function duplicateRule(string $fieldName, string $owner): string
    {
        if ($fieldName === 'canonical_name' || $fieldName === 'preferred_title') {
            return 'Resolve and reuse the existing Music Authority identity by canonical scope; display-name equality never creates an owner.';
        }
        if (str_contains($owner, 'Dictionary')) {
            return 'Search and reuse the existing Entry/Form/Sense and preserve ambiguity; an alias is not a new semantic owner.';
        }
        if (str_contains($owner, 'Media') || str_contains($owner, 'Score')) {
            return 'Reuse an exact canonical Media/MediaAsset identity by governed read-back, checksum and scope; a URL or local file is not identity.';
        }
        return 'Reuse an existing scoped claim, source, relation or owner after canonical read-back; do not create a duplicate from wording alone.';
    }

    private function reviewRule(string $fieldName, string $owner): string
    {
        if ($fieldName === 'registered_relation' || str_contains($owner, 'Graph')) {
            return 'Review endpoint types, registered predicate, direction, scope, provenance and evidence before any Governance proposal.';
        }
        if (str_contains($owner, 'Dictionary')) {
            return 'Review lexical meaning, locale, usage scope, attestation and owner revalidation; unresolved ambiguity remains review-required.';
        }
        if (str_contains($owner, 'Media') || str_contains($owner, 'Score')) {
            return 'Review provenance, integrity, rights, readiness and public delivery separately; local preview never approves publication.';
        }
        return 'Review subject identity, scope, source/locator, uncertainty and evidence; canonical mutation uses the existing Governance lifecycle.';
    }

    private function frontendConsumer(string $fieldName, string $owner): string
    {
        if (str_contains($owner, 'Dictionary')) return 'Dictionary public projection or delegated canonical-owner link; no Music template inference.';
        if (str_contains($owner, 'Media') || str_contains($owner, 'Score')) return 'MusicDossierProjection score/audio/library sections when owner readiness and public delivery pass.';
        if (str_contains($owner, 'Graph')) return 'MusicDossierProjection related-entities section with direct/derived origin and public-safe path.';
        if (str_contains($owner, 'Public Projection') || str_contains($owner, 'WordPress')) return 'Generic Music dossier identity, section or SEO projection through the existing route/read model.';
        return 'MusicCoverageAssessment for readiness; MusicDossierProjection only after the owning public eligibility policy passes.';
    }
}
