<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Demo;

final class PluginHeaderVersionReader
{
    public static function read(string $pluginFile): ?string
    {
        $contents = is_readable($pluginFile) ? file_get_contents($pluginFile) : false;
        if (!is_string($contents) || preg_match('/^\s*\*?\s*Version:\s*([^\s]+)\s*$/mi', $contents, $match) !== 1) {
            return null;
        }

        return trim($match[1]);
    }
}
