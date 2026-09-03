<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use DateTimeImmutable;
use NHK\Core\Domain\Article\OwnerPublicationDecision;
use PHPUnit\Framework\TestCase;

final class OwnerPublicationDecisionTest extends TestCase
{
    public function test_owner_approval_expires_at_thirty_minutes_and_contains_no_body(): void
    {
        $decision = OwnerPublicationDecision::approved('decision-1', 'publish-key', 87, str_repeat('a', 64), str_repeat('b', 64), 'owner-1', '2026-09-03T10:00:00+00:00');
        self::assertFalse($decision->isExpired(new DateTimeImmutable('2026-09-03T10:29:59+00:00')));
        self::assertTrue($decision->isExpired(new DateTimeImmutable('2026-09-03T10:30:00+00:00')));
        self::assertArrayNotHasKey('body', $decision->toArray());
    }
}
