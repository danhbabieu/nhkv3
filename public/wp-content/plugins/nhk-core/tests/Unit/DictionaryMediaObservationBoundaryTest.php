<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DictionaryMediaObservationBoundaryTest extends TestCase
{
    public function test_attachment_title_and_alt_are_not_promoted_to_explicit_dictionary_hints(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Dictionary/DictionaryWordPressBridge.php');
        self::assertStringContainsString("'weak_sources' => ['title', 'alt', 'filename']", $source);
        self::assertStringNotContainsString('$hints = array_values(array_filter([(string) $post->post_title, $alt]));', $source);
        self::assertStringContainsString("$this->observe('MEDIA', (string) $attachmentId, $text, ['attachment_id' => $attachmentId, 'weak_sources' => ['title', 'alt', 'filename']]);", $source);
    }
}
