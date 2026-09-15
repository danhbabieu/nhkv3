<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Infrastructure\Media\PrivateMediaSourceStorage;
use PHPUnit\Framework\TestCase;

final class PrivateMediaSourceStorageTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $files = is_dir($root) ? (glob($root . '/**/*', GLOB_NOSORT) ?: []) : [];
            foreach (array_reverse($files) as $file) if (is_file($file)) @unlink($file);
            if (is_dir($root)) @rmdir($root);
        }
    }

    public function test_stores_source_bytes_under_private_root_without_a_public_url(): void
    {
        $root = sys_get_temp_dir() . '/nhk-private-' . bin2hex(random_bytes(4));
        $this->roots[] = $root;
        $storage = new PrivateMediaSourceStorage($root);
        $key = $storage->store('private source bytes', 'jpg');

        self::assertStringStartsWith('private/', $key);
        self::assertFileExists($storage->path($key));
        self::assertStringNotContainsString('/uploads/', $storage->path($key));
        self::assertNull($storage->publicUrl($key));
        self::assertSame('private source bytes', file_get_contents((string) $storage->path($key)));
    }

    public function test_rejects_path_traversal_and_unknown_extensions(): void
    {
        $root = sys_get_temp_dir() . '/nhk-private-' . bin2hex(random_bytes(4));
        $this->roots[] = $root;
        $storage = new PrivateMediaSourceStorage($root);

        $this->expectException(\InvalidArgumentException::class);
        $storage->path('../outside.jpg');
    }
}
