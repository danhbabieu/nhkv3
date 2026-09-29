<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryTermDetector;
use PHPUnit\Framework\TestCase;

final class DictionaryTermDetectorTest extends TestCase
{
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
            ['7 rods 9 hammers', '7 rods 9 hammers are used'],
            ['13 rods 14 hammers', '13 rods 14 hammers for the mechanism'],
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

}
