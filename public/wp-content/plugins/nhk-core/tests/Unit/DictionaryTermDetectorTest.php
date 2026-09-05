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
}
