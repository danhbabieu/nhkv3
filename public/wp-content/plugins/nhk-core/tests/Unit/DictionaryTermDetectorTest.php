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

}
