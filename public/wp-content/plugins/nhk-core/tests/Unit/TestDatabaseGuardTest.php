<?php
declare(strict_types=1);
namespace NHK\Tests\Unit;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;
final class TestDatabaseGuardTest extends TestCase {
    public function test_uninitialized_wordpress_runtime_is_not_cleaned_up(): void { self::assertFalse(TestDatabaseGuard::isInitialized(null)); }
    public function test_test_database_is_allowed(): void { TestDatabaseGuard::assertDestructiveAllowed('nhk_v3_test'); self::assertTrue(true); }
    public function test_development_database_is_rejected(): void { $this->expectException(\RuntimeException::class); TestDatabaseGuard::assertDestructiveAllowed('nhk_v3'); }
}
