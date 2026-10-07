<?php
declare(strict_types=1);

namespace NHK\Core\Application\Presentation;

/** Read-only guard for the public new-record-zero-files architecture. */
final class PublicTemplateFamilyContract
{
    /** @return array{forbidden_files:list<string>,forbidden_references:list<string>} */
    public static function auditTheme(string $themeDirectory): array
    {
        $forbiddenFiles = [];
        $forbiddenReferences = [];
        if (!is_dir($themeDirectory)) return ['forbidden_files' => [], 'forbidden_references' => []];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($themeDirectory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) continue;
            $path = $file->getPathname();
            $relative = ltrim(str_replace($themeDirectory, '', $path), DIRECTORY_SEPARATOR);
            if (preg_match('/^(?:single-post|brand|model|movement|component|video|dictionary)-[a-z0-9][a-z0-9_-]*\.php$/i', $file->getBasename()) === 1) {
                $forbiddenFiles[] = $relative;
            }
            if (!in_array(strtolower($file->getExtension()), ['php', 'inc'], true)) continue;
            $source = (string) file_get_contents($path);
            foreach ([
                '/(?:canonical_id|canonical_uuid|uuid)\s*[=!]==?\s*[\'\"]/i',
                '/(?:slug|stable_key)\s*[=!]==?\s*[\'\"]/i',
                '/(?:name|title)\s*[=!]==?\s*[\'\"](?:odo|hermle|vách dày|côn hoa thị|sonodo)/iu',
            ] as $pattern) {
                if (preg_match($pattern, $source) === 1) $forbiddenReferences[] = $relative;
            }
        }
        sort($forbiddenFiles);
        sort($forbiddenReferences);
        return ['forbidden_files' => array_values(array_unique($forbiddenFiles)), 'forbidden_references' => array_values(array_unique($forbiddenReferences))];
    }

    /** @param array<string,mixed> $record */
    public static function templateFor(string $family, array $record = []): string
    {
        return match (strtolower($family)) {
            'article' => 'single.php',
            'video' => 'video.php',
            'dictionary', 'dictionary_entry', 'dictionary_sense' => 'dictionary.php',
            'brand', 'model', 'variant', 'movement', 'music', 'component', 'classification', 'clock_type', 'specimen', 'product' => 'entity.php',
            default => throw new \InvalidArgumentException('Unknown public template family: ' . $family),
        };
    }
}
