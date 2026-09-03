<?php
declare(strict_types=1);

namespace NHK\Core\Application\Media;

final class PublicMediaAssetUrlResolver
{
    public function path(string $filename): string
    {
        return '/anh/' . rawurlencode($this->filename($filename));
    }

    /** @param list<string> $existing @return string */
    public function collisionSafeFilename(string $filename, array $existing, ?string $assetId = null): string
    {
        $filename = $this->filename($filename);
        if ($assetId !== null && $assetId !== '') return $filename;
        if (!in_array($filename, $existing, true)) return $filename;
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $number = 2;
        do { $candidate = $stem . '-' . $number++ . '.' . $extension; } while (in_array($candidate, $existing, true));
        return $candidate;
    }

    private function filename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $stem = (new MediaFilenameNormalizer())->normalizeWebp($stem, '', '');
        return $stem;
    }
}
