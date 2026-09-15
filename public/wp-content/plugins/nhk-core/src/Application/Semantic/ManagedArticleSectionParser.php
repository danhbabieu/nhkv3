<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Parses only NHK-owned section markers; user-authored prose is opaque. */
final class ManagedArticleSectionParser
{
    public function wrap(string $sectionId, string $fingerprint, string $dependencyFingerprint, string $content): string
    {
        $metadata = json_encode([
            'section_id' => $sectionId,
            'fingerprint' => $fingerprint,
            'dependency_fingerprint' => $dependencyFingerprint,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return '<!-- nhk-managed-section ' . $metadata . ' -->' . "\n" . trim($content) . "\n" . '<!-- /nhk-managed-section -->';
    }

    /** @param list<array<string,mixed>> $sections */
    public function removeOwned(string $content, array $sections): string
    {
        $expected = [];
        foreach ($sections as $section) {
            $id = trim((string) ($section['section_id'] ?? ''));
            if ($id !== '') $expected[$id] = ['fingerprint' => (string) ($section['fingerprint'] ?? ''), 'dependency_fingerprint' => (string) ($section['dependency_fingerprint'] ?? '')];
        }
        if ($expected === []) return $content;
        $pattern = '/<!-- nhk-managed-section\s+(\{.*?\})\s*-->\s*(.*?)\s*<!-- \/nhk-managed-section -->/is';
        $updated = preg_replace_callback($pattern, static function (array $match) use ($expected): string {
            $metadata = json_decode((string) ($match[1] ?? ''), true);
            $id = is_array($metadata) ? trim((string) ($metadata['section_id'] ?? '')) : '';
            if ($id === '' || !array_key_exists($id, $expected)) return $match[0];
            $body = trim((string) ($match[2] ?? ''));
            $actual = hash('sha256', $body);
            if ($expected[$id]['fingerprint'] !== '' && !hash_equals($expected[$id]['fingerprint'], $actual)) throw new ManagedArticleSectionConflict($id);
            $dependency = (string) ($metadata['dependency_fingerprint'] ?? '');
            if ($expected[$id]['dependency_fingerprint'] !== '' && !hash_equals($expected[$id]['dependency_fingerprint'], $dependency)) throw new ManagedArticleSectionConflict($id);
            return '';
        }, $content);
        return trim(preg_replace('/\n{3,}/', "\n\n", is_string($updated) ? $updated : $content) ?? $content);
    }
}
