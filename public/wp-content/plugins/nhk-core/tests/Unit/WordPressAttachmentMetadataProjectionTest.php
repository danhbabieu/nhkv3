<?php
declare(strict_types=1);

namespace NHK\Core\Tests\Unit;

use NHK\Core\Infrastructure\Media\{WordPressAttachmentMetadataPort, WordPressAttachmentMetadataProjection};
use PHPUnit\Framework\TestCase;

final class WordPressAttachmentMetadataProjectionTest extends TestCase
{
    public function test_absent_fields_are_not_written_and_provided_empty_and_values_round_trip(): void
    {
        $port = new FakeAttachmentMetadataPort();
        $projection = new WordPressAttachmentMetadataProjection($port);

        self::assertSame(['title' => '', 'alt_text' => '', 'caption' => '', 'description' => ''], $projection->apply(41, [
            'title' => '', 'alt_text' => '', 'caption' => '', 'description' => '',
        ]));
        self::assertSame(['post_title' => '', 'post_excerpt' => '', 'post_content' => ''], $port->postWrites[0]);
        self::assertSame(['alt_text' => ''], $port->metaWrites[0]);

        $projection->apply(41, ['title' => 'Provided title', 'caption' => 'Provided caption']);
        self::assertSame(['post_title' => 'Provided title', 'post_excerpt' => 'Provided caption'], $port->postWrites[1]);
        self::assertCount(1, $port->metaWrites);
        self::assertSame('Provided title', $projection->read(41)['title']);
        self::assertSame('', $projection->read(41)['alt_text']);
        self::assertSame('', $projection->read(41)['description']);
    }

    public function test_absent_does_not_clear_existing_wordpress_values(): void
    {
        $port = new FakeAttachmentMetadataPort(['title' => 'Existing', 'alt_text' => 'Existing alt', 'caption' => 'Existing caption', 'description' => 'Existing body']);
        $projection = new WordPressAttachmentMetadataProjection($port);

        self::assertSame(['title' => 'Existing', 'alt_text' => 'Existing alt', 'caption' => 'Existing caption', 'description' => 'Existing body'], $projection->apply(41, []));
        self::assertCount(0, $port->postWrites);
        self::assertCount(0, $port->metaWrites);
    }
}

final class FakeAttachmentMetadataPort implements WordPressAttachmentMetadataPort
{
    /** @var array<string,string> */
    private array $values;
    /** @var list<array<string,string>> */
    public array $postWrites = [];
    /** @var list<array<string,string>> */
    public array $metaWrites = [];

    /** @param array<string,string> $values */
    public function __construct(array $values = [])
    {
        $this->values = $values + ['title' => '', 'alt_text' => '', 'caption' => '', 'description' => ''];
    }

    public function updatePost(int $attachmentId, array $fields): bool
    {
        $this->postWrites[] = $fields;
        $this->values['title'] = $fields['post_title'] ?? $this->values['title'];
        $this->values['caption'] = $fields['post_excerpt'] ?? $this->values['caption'];
        $this->values['description'] = $fields['post_content'] ?? $this->values['description'];
        return true;
    }

    public function updateAlt(int $attachmentId, string $altText): bool
    {
        $this->metaWrites[] = ['alt_text' => $altText];
        $this->values['alt_text'] = $altText;
        return true;
    }

    public function read(int $attachmentId): ?array { return $this->values; }
}
