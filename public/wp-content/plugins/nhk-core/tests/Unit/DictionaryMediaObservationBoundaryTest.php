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
        self::assertStringNotContainsString(", ['attachment_id' => \$attachmentId], \$hints)", $source);
        self::assertStringContainsString("'MEDIA', (string) \$attachmentId, \$text, ['attachment_id' => \$attachmentId, 'weak_sources' => ['title', 'alt', 'filename']]", $source);
    }

    public function test_dictionary_illustration_metadata_remains_usage_context_and_never_becomes_media_or_evidence_truth(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Dictionary/DictionaryCurationService.php');
        $bridge = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Dictionary/DictionaryWordPressBridge.php');

        self::assertStringNotContainsString('Media::create', $service);
        self::assertStringNotContainsString('MediaAsset::create', $service);
        self::assertStringNotContainsString('Evidence', $service);
        self::assertStringNotContainsString('Graph', $service);
        self::assertStringNotContainsString('wp_update_post', $bridge);
        self::assertStringNotContainsString('update_post_meta', $bridge);
        self::assertStringNotContainsString('set_post_thumbnail', $bridge);
        self::assertStringContainsString('weak_sources', $bridge);
    }
}
