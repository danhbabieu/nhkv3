<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryDetailPresentationComposer;
use PHPUnit\Framework\TestCase;

final class DictionaryDetailPresentationComposerTest extends TestCase
{
    public function test_composes_one_public_packet_without_internal_identity_fields(): void
    {
        $source = [
            'status' => 'READY',
            'item' => [
                'entry_id' => 'entry-internal',
                'title' => 'Vách dày',
                'description' => 'Định nghĩa công khai.',
                'url' => '/tu-dien/vach-day/',
                'forms' => [
                    ['form' => 'Vách dày', 'kind' => 'PREFERRED', 'locale' => 'vi-VN'],
                    ['form' => 'Odo vách dày', 'kind' => 'ALTERNATE', 'locale' => 'vi-VN'],
                    ['form' => 'Odo vách dày', 'kind' => 'ALTERNATE', 'locale' => 'vi-VN'],
                ],
                'labels' => [
                    ['label' => '36 vách dày', 'kind' => 'COLLOQUIAL', 'locale' => 'vi-VN'],
                    ['label' => 'Hidden alias', 'kind' => 'HIDDEN', 'locale' => 'vi-VN'],
                ],
                'senses' => [[
                    'sense_id' => 'sense-internal',
                    'title' => 'Vách dày',
                    'description' => 'Định nghĩa công khai.',
                    'context' => [
                        'usage_scope' => ['kỹ thuật'],
                        'usage_notes' => ['Tên gọi trong giới sưu tầm.'],
                    ],
                    'semantic_reference' => [
                        'status' => 'AVAILABLE',
                        'type' => 'model',
                        'id' => 'owner-internal',
                        'revision' => 4,
                    ],
                    'canonical_owner' => [
                        'type' => 'model',
                        'id' => 'owner-internal',
                        'title' => 'Odo 36',
                        'url' => '/mau/odo-36/',
                    ],
                    'knowledge' => [
                        'status' => 'AVAILABLE_WITH_ITEMS',
                        'items' => [['id' => 'claim-internal', 'text' => 'Claim công khai.']],
                    ],
                ]],
                'mentions' => ['status' => 'AVAILABLE_EMPTY', 'groups' => []],
            ],
            'seo' => [
                'state' => 'INDEXABLE',
                'canonical' => '/tu-dien/vach-day/',
                'robots' => 'index,follow',
                'sitemap' => true,
                'indexable' => true,
            ],
        ];

        $packet = (new DictionaryDetailPresentationComposer())->compose($source);

        self::assertSame('READY', $packet['status']);
        self::assertSame('/tu-dien/vach-day/', $packet['route']['canonical_url']);
        self::assertSame('Vách dày', $packet['identity']['title']);
        self::assertSame(['Odo vách dày', '36 vách dày'], array_column($packet['lexical']['alternate_forms'], 'form'));
        self::assertSame(['kỹ thuật'], $packet['senses'][0]['usage_scope']);
        self::assertSame(['Tên gọi trong giới sưu tầm.'], $packet['senses'][0]['usage_notes']);
        self::assertSame('Odo 36', $packet['canonical_owner']['title']);
        self::assertSame('Claim công khai.', $packet['knowledge']['items'][0]['text']);
        self::assertArrayNotHasKey('id', $packet['canonical_owner']);
        self::assertArrayNotHasKey('id', $packet['senses'][0]);
        self::assertArrayNotHasKey('id', $packet['knowledge']['items'][0]);
        self::assertStringNotContainsString('Hidden alias', json_encode($packet, JSON_UNESCAPED_UNICODE));
    }

    public function test_delegated_packet_uses_owner_canonical_and_normalized_availability(): void
    {
        $source = [
            'status' => 'READY',
            'item' => [
                'title' => 'Côn hoa thị',
                'description' => 'Linh kiện được tra cứu.',
                'url' => '/tu-dien/con-hoa-thi/',
                'forms' => [],
                'labels' => [],
                'senses' => [[
                    'title' => 'Côn hoa thị',
                    'description' => 'Linh kiện được tra cứu.',
                    'context' => [],
                    'canonical_owner' => ['type' => 'component', 'title' => 'Côn hoa thị', 'url' => '/linh-kien/con-hoa-thi/'],
                    'knowledge' => ['status' => 'UNAVAILABLE_IMPLEMENTATION_GAP', 'items' => []],
                    'media' => ['status' => 'AVAILABLE_EMPTY', 'items' => []],
                ]],
                'mentions' => ['status' => 'AVAILABLE_EMPTY', 'groups' => []],
            ],
            'seo' => ['state' => 'NOINDEX', 'canonical' => '/tu-dien/con-hoa-thi/', 'robots' => 'noindex,follow', 'sitemap' => false, 'indexable' => false],
        ];

        $packet = (new DictionaryDetailPresentationComposer())->compose($source, [
            'mode' => 'delegated',
            'canonical_url' => '/linh-kien/con-hoa-thi/',
        ]);

        self::assertSame('DELEGATED', $packet['route']['mode']);
        self::assertTrue($packet['route']['delegated']);
        self::assertSame('/linh-kien/con-hoa-thi/', $packet['route']['canonical_url']);
        self::assertSame('UNAVAILABLE', $packet['knowledge']['status']);
        self::assertSame('UNAVAILABLE', $packet['knowledge']['availability']);
        self::assertSame('AVAILABLE_EMPTY', $packet['media']['status']);
        self::assertFalse($packet['seo']['indexable']);
    }
}
