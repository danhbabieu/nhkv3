<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryTermDetector;
use PHPUnit\Framework\TestCase;

final class DictionaryTermDetectorTest extends TestCase
{
    public function test_generic_noun_phrases_stop_before_editorial_continuations(): void
    {
        $detector = new DictionaryTermDetector();
        $terms = static fn (string $text): array => array_column($detector->detect($text), 'normalized_term');

        self::assertContains('pressure valve', $terms('The pressure valve is also quite unusual.'));
        self::assertContains('bộ truyền động', $terms('bộ truyền động cũng khá đặc biệt'));
        self::assertContains('van áp suất', $terms('van áp suất thì cũng khá lớn'));
        self::assertContains('hệ thống làm mát', $terms('hệ thống làm mát nghe rất êm'));
        self::assertContains('cụm bánh răng', $terms('cụm bánh răng này nhìn khá đặc biệt'));
        self::assertNotContains('truyền động cũng khá đặc', $terms('bộ truyền động cũng khá đặc biệt'));
    }

    public function test_editorial_tail_is_reported_separately_from_lexical_spans(): void
    {
        $detector = new DictionaryTermDetector();

        $signals = $detector->editorialSignals('bộ truyền động cũng khá đặc biệt');

        self::assertSame(['khá đặc biệt'], array_column($signals, 'term'));
        self::assertSame('EDITORIAL_SIGNAL', $signals[0]['origin']);
    }

    public function test_editorial_only_text_has_no_lexical_candidate(): void
    {
        $terms = array_column((new DictionaryTermDetector())->detect('rất đẹp và cực kỳ hiếm'), 'normalized_term');

        self::assertSame([], $terms);
    }

    public function test_detects_clock_domain_phrases_without_requiring_existing_dictionary_entry(): void
    {
        $items = (new DictionaryTermDetector())->detect('Chiếc đồng hồ dùng côn lòng máng trắng và có cơ chế ngắt chuông đêm.');
        $terms = array_column($items, 'normalized_term');
        self::assertContains('côn lòng máng trắng', $terms);
        self::assertContains('ngắt chuông đêm', $terms);
    }

    public function test_does_not_turn_generic_marketing_phrase_into_dictionary_candidate(): void
    {
        $items = (new DictionaryTermDetector())->detect('Chiếc máy đẹp, sạch và rất ấn tượng.');
        self::assertNotContains('máy đẹp', array_column($items, 'normalized_term'));
    }

    public function test_quality_gate_cuts_clause_tails_but_keeps_reusable_lexical_phrase(): void
    {
        $detector = new DictionaryTermDetector();
        $terms = static fn (string $text): array => array_column($detector->detect($text), 'normalized_term');

        self::assertContains('mặt số lớn', $terms('Mặt số lớn mà chúng ta thường thấy trên đồng hồ.'));
        self::assertNotContains('mặt số lớn mà chúng', $terms('Mặt số lớn mà chúng ta thường thấy trên đồng hồ.'));
        self::assertContains('mặt số', $terms('Mặt số như thế nào và nằm ở đâu?'));
        self::assertContains('ngắt chuông đêm tự động', $terms('Thiết bị có ngắt chuông đêm tự động và bộ thoát.'));
        self::assertNotContains('ngắt chuông đêm tự', $terms('Thiết bị có ngắt chuông đêm tự động và bộ thoát.'));
        self::assertContains('bộ thoát', $terms('Thiết bị có ngắt chuông đêm tự động và bộ thoát.'));
    }

    public function test_quality_gate_cuts_multi_word_clause_boundary_without_losing_the_lexical_phrase(): void
    {
        $terms = static fn (string $text): array => array_column((new DictionaryTermDetector())->detect($text), 'normalized_term');

        self::assertNotContains('côn thay', $terms('Tình trạng bộ côn thay vì dùng từ tuyệt đối.'));
        self::assertContains('côn', $terms('Tình trạng bộ côn thay vì dùng từ tuyệt đối.'));
    }

    public function test_detector_keeps_known_music_name_but_drops_editorial_modifier_before_it(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect(
                'Bản nhạc đầy đủ Gai-Carillon trên Odo 36/10.',
                ['Gai-Carillon'],
            ),
            'normalized_term',
        );

        self::assertContains('gai-carillon', $terms);
        self::assertNotContains('đầy đủ gai-carillon', $terms);
    }

    public function test_quality_gate_keeps_reusable_short_domain_phrases(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect(
                'côn lòng máng trắng, ngắt chuông đêm tự động, mặt số lớn, bộ thoát, điểm giờ và vách mảnh.',
            ),
            'normalized_term',
        );

        foreach (['côn lòng máng trắng', 'ngắt chuông đêm tự động', 'mặt số lớn', 'bộ thoát', 'điểm giờ', 'vách mảnh'] as $term) {
            self::assertContains($term, $terms);
        }
    }

    public function test_generic_clause_continuations_are_not_lexical_tails(): void
    {
        $detector = new DictionaryTermDetector();
        $terms = static fn (string $text): array => array_column($detector->detect($text), 'normalized_term');

        self::assertContains('bộ alpha', $terms('“Bộ Alpha” thay vì Beta.'));
        self::assertNotContains('bộ alpha thay vì beta', $terms('“Bộ Alpha” thay vì Beta.'));
        self::assertContains('cụm gamma', $terms('“Cụm Gamma” dùng để Delta.'));
        self::assertNotContains('cụm gamma dùng để delta', $terms('“Cụm Gamma” dùng để Delta.'));
        self::assertContains('hệ epsilon', $terms('“Hệ Epsilon” đến phần Zeta.'));
        self::assertNotContains('hệ epsilon đến phần zeta', $terms('“Hệ Epsilon” đến phần Zeta.'));
    }

    public function test_numeric_configuration_keeps_structural_configuration_without_following_clause(): void
    {
        $detector = new DictionaryTermDetector();
        $terms = static fn (string $text): array => array_column($detector->detect($text), 'normalized_term');

        foreach ([
            ['13 côn 14 búa', '13 côn 14 búa for the mechanism'],
        ] as [$expected, $text]) {
            self::assertContains($expected, $terms($text));
            self::assertNotContains($expected . ' are used', $terms($text));
            self::assertNotContains($expected . ' for the mechanism', $terms($text));
        }
    }

    public function test_unknown_hyphenated_and_multi_token_names_are_retained_as_candidates(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect('“Alpha-Beta” và “Monte Verde”, Sonata-X.'),
            'normalized_term',
        );

        self::assertContains('alpha-beta', $terms);
        self::assertContains('monte verde', $terms);
        self::assertContains('sonata-x', $terms);
    }

    public function test_approved_adjacent_units_are_not_collapsed_into_an_unbounded_phrase(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect(
                'Alpha Beta Gamma Delta',
                ['Alpha Beta', 'Gamma Delta'],
            ),
            'normalized_term',
        );

        self::assertContains('alpha beta', $terms);
        self::assertContains('gamma delta', $terms);
        self::assertNotContains('alpha beta gamma delta', $terms);
    }

    public function test_longest_approved_phrase_wins_over_nested_shorter_labels(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect(
                'Alpha Beta Gamma',
                ['Alpha', 'Alpha Beta', 'Alpha Beta Gamma'],
            ),
            'normalized_term',
        );

        self::assertSame(['alpha beta gamma'], $terms);
    }

    public function test_prose_after_a_trigger_does_not_become_a_dictionary_term(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect('Mặt số đẹp, sáng và phù hợp với căn phòng rộng rãi.'),
            'normalized_term',
        );

        self::assertNotContains('mặt số đẹp sáng và phù hợp với căn phòng', $terms);
    }

    public function test_production_noise_is_trimmed_by_boundaries_and_adjacent_known_labels(): void
    {
        $labels = ['Westminster', 'mặt số nổi', 'côn đồng bạch', 'côn'];
        $detector = new DictionaryTermDetector();
        $terms = static fn (string $text): array => array_column($detector->detect($text, $labels), 'normalized_term');

        self::assertNotContains('côn không bắt buộc', $terms('côn không bắt buộc'));
        self::assertNotContains('côn 10 búa hai', $terms('côn 10 búa hai'));
        self::assertNotContains('mặt số nổi avemaria loudes', $terms('mặt số nổi avemaria loudes'));
        self::assertNotContains('côn 5 búa westminster', $terms('côn 5 búa westminster'));
        self::assertNotContains('búa westminster', $terms('búa westminster'));
        self::assertContains('côn 8 búa', $terms('côn 8 búa đến'));
        self::assertContains('côn 111', $terms('côn 111 dùng'));
        self::assertContains('côn đồng bạch', $terms('côn đồng bạch hiệu'));
    }

    public function test_generic_article_prose_fragments_are_rejected_before_dictionary_lookup(): void
    {
        $terms = array_column((new DictionaryTermDetector())->detect(
            'Odo 36/10 là dòng được nhiều người yêu thích. Đây là một chiếc đồng hồ có giá trị sưu tầm cao và tương đối hiếm. Cần tách riêng độ hiếm và giá trị sưu tầm, vì hai thuộc tính không luôn đồng nghĩa.'
        ), 'normalized_term');

        self::assertContains('odo 36/10', $terms);
        self::assertContains('đồng hồ', $terms);
        foreach (['nhiều người yêu thích', 'đây', 'giá trị sưu tầm cao', 'tương đối', 'cần tách riêng độ', 'thuộc tính', 'luôn đồng nghĩa'] as $fragment) {
            self::assertNotContains($fragment, $terms);
        }
    }

    public function test_composite_numeric_configuration_preserves_the_left_boundary_and_hides_fragments(): void
    {
        $units = [
            ['kind' => 'STRUCTURAL_UNIT', 'term' => 'alpha'],
            ['kind' => 'STRUCTURAL_UNIT', 'term' => 'beta'],
            ['kind' => 'STRUCTURAL_UNIT', 'term' => 'gamma'],
            ['kind' => 'STRUCTURAL_UNIT', 'term' => 'delta'],
            ['kind' => 'STRUCTURAL_UNIT', 'term' => 'epsilon'],
        ];
        $terms = static fn (string $text): array => array_column((new DictionaryTermDetector())->detect($text, [], $units), 'normalized_term');

        $first = $terms('Cấu hình thử nghiệm có 17 alpha 19 beta và một cơ cấu khác.');
        self::assertContains('17 alpha 19 beta', $first);
        self::assertNotContains('19 beta', $first);
        self::assertNotContains('beta', $first);

        $second = $terms('Cấu hình thử nghiệm có 23 gamma 27 delta 3 epsilon.');
        self::assertContains('23 gamma 27 delta 3 epsilon', $second);
        self::assertNotContains('27 delta 3 epsilon', $second);
    }

    public function test_arbitrary_number_and_normal_noun_are_not_structural_configuration(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect('Tài liệu có 30 câu hỏi và 12 trang phụ lục.'),
            'normalized_term',
        );

        self::assertNotContains('30 câu', $terms);
        self::assertNotContains('12 trang', $terms);
    }

    public function test_invalid_second_structural_unit_fails_closed_without_interior_fragment(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect('Cấu hình có 17 alpha 19 câu.', [], [['kind' => 'STRUCTURAL_UNIT', 'term' => 'alpha']]),
            'normalized_term',
        );

        self::assertNotContains('17 alpha 19 câu', $terms);
        self::assertNotContains('19 câu', $terms);
    }

    public function test_identifier_reference_spans_protect_numeric_suffixes(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect('Omega 47/13, ZX-42/7 và Ref 81.12 được ghi nhận.'),
            'normalized_term',
        );

        self::assertContains('omega 47/13', $terms);
        self::assertContains('zx-42/7', $terms);
        self::assertContains('ref 81.12', $terms);
        self::assertNotContains('13', $terms);
        self::assertNotContains('7', $terms);
        self::assertNotContains('12', $terms);
    }

    public function test_prose_slash_forms_are_not_identifier_spans(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect('máy/mặt, bài/nốt, ngày/8 và côn/gông.'),
            'normalized_term',
        );

        foreach (['máy/mặt', 'bài/nốt', 'ngày/8', 'côn/gông'] as $term) {
            self::assertNotContains($term, $terms);
        }
    }

    public function test_prose_leading_tokens_are_not_pulled_into_numeric_identifier(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect('và 36/8, cổ 36/10, Omega 47/13.'),
            'normalized_term',
        );

        self::assertNotContains('và 36/8', $terms);
        self::assertNotContains('cổ 36/10', $terms);
        self::assertContains('omega 47/13', $terms);
    }

    public function test_proper_name_scanner_keeps_capitalized_hyphenated_continuation(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect('Anne-Marie Dupont và Jean-Paul Van Buren được nhắc đến.'),
            'normalized_term',
        );

        self::assertContains('anne-marie dupont', $terms);
        self::assertContains('jean-paul van buren', $terms);
        self::assertNotContains('anne-marie', $terms);
        self::assertNotContains('jean-paul', $terms);
    }

    public function test_atomic_term_is_kept_independently_but_not_as_a_configuration_fragment(): void
    {
        $detector = new DictionaryTermDetector();
        self::assertContains('búa', array_column($detector->detect('Búa được kiểm tra độc lập.'), 'normalized_term'));

        $composite = array_column($detector->detect('17 alpha 19 beta.'), 'normalized_term');
        self::assertNotContains('beta', $composite);
    }

    public function test_contextual_numeric_alias_is_preserved_when_explicitly_signaled(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect('Mã collector 528 được ghi nhận.', ['528']),
            'normalized_term',
        );

        self::assertContains('528', $terms);
    }

    public function test_numeric_only_designation_is_not_candidate_without_contextual_reason(): void
    {
        $terms = array_column(
            (new DictionaryTermDetector())->detect('Mã 528 được ghi nhận.'),
            'normalized_term',
        );

        self::assertNotContains('528', $terms);
    }

    public function test_editorial_process_phrases_are_not_lexical_candidates_but_terms_and_identifiers_survive(): void
    {
        $detector = new DictionaryTermDetector();
        $terms = array_column($detector->detect(
            'Hãy hỏi, cảm thấy, chỉ cần nhớ và đừng vội hỏi. Anton Schneider bắt nguồn; Deutsches Uhrenmuseum ghi nhận. Hiện vật mang đồng thời nhiều đặc điểm; bộ máy hoàn toàn nguyên bản. 8 côn 8 búa, 10 côn 11 búa, bộ thoát, mặt số, Odo 36/10.'
        ), 'normalized_term');

        foreach (['hãy hỏi', 'cảm thấy', 'chỉ cần nhớ', 'đừng vội hỏi', 'anton schneider bắt nguồn', 'deutsches uhrenmuseum ghi nhận', 'hiện vật mang đồng thời nhiều', 'bộ máy hoàn toàn nguyên bản'] as $noise) {
            self::assertNotContains($noise, $terms);
        }
        foreach (['8 côn 8 búa', '10 côn 11 búa', 'bộ thoát', 'mặt số', 'odo 36/10'] as $term) {
            self::assertContains($term, $terms);
        }
    }

}
