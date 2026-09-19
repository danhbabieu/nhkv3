<?php
declare(strict_types=1);

namespace NHK\Core\Tests\Unit;

use NHK\Core\Domain\Media\MediaMetadataContract;
use PHPUnit\Framework\TestCase;

final class MediaMetadataContractTest extends TestCase
{
    public function testCreateAllowsOptionalFieldsAndPatchDistinguishesStates(): void
    {
        $contract = MediaMetadataContract::fromArray(['title' => 'Ảnh đồng hồ', 'alt_text' => '', 'description' => 'mô tả']);

        self::assertSame(MediaMetadataContract::PROVIDED, $contract->state('title'));
        self::assertSame(MediaMetadataContract::EMPTY, $contract->state('alt_text'));
        self::assertSame(MediaMetadataContract::ABSENT, $contract->state('caption'));
        self::assertSame(['title' => 'Ảnh đồng hồ', 'alt_text' => '', 'description' => 'mô tả'], $contract->toArray());
    }

    public function testUnknownOrNonStringFieldsFailClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MediaMetadataContract::fromArray(['caption' => ['not' => 'text']]);
    }
}
