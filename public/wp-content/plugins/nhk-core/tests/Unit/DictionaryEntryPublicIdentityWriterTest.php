<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryEntryPublicIdentityWriter;
use NHK\Core\Domain\Dictionary\{DictionaryConcept, LexicalEntry};
use PHPUnit\Framework\TestCase;

final class DictionaryEntryPublicIdentityWriterTest extends TestCase
{
    public function test_assigns_shared_policy_slug_and_preserves_it_on_replay(): void
    {
        $writer = new DictionaryEntryPublicIdentityWriter(static fn (string $slug, ?string $entryId = null): bool => false);
        $entry = new LexicalEntry('entry-1', 'Kính rào', 'kính rào', DictionaryConcept::DRAFT, 'vi-VN');

        $first = $writer->assign($entry);
        $replay = $writer->assign($first);

        self::assertSame('kinh-rao', $first->context['public_slug']);
        self::assertSame($first, $replay);
    }

    public function test_collision_uses_meaningful_context_deterministically_and_fails_without_one(): void
    {
        $writer = new DictionaryEntryPublicIdentityWriter(static fn (string $slug, ?string $entryId = null): bool => $slug === 'kinh-rao');
        $entry = new LexicalEntry('entry-2', 'Kính rào', 'kính rào', DictionaryConcept::DRAFT, 'vi-VN', ['domain' => 'clock']);

        self::assertSame('kinh-rao-clock', $writer->assign($entry)->context['public_slug']);

        $this->expectExceptionMessage('PUBLIC_SLUG_COLLISION_REQUIRES_RECONCILIATION');
        $writer->assign(new LexicalEntry('entry-3', 'Kính rào', 'kính rào', DictionaryConcept::DRAFT, 'vi-VN'));
    }
}
