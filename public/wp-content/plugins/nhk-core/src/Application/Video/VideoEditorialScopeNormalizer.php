<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

/** Keeps user observations specimen-scoped while canonical context stays separately addressable. */
final class VideoEditorialScopeNormalizer
{
    /** @param array<string,mixed>|null $subject */
    public function topic(string $userHint, ?array $subject, string $instruction = ''): string
    {
        $instruction = trim($instruction);
        if ($instruction !== '') return $instruction;
        $subjectName = trim((string) ($subject['name'] ?? ''));
        return $subjectName !== '' ? $subjectName : trim($userHint);
    }

    /** @param array<string,mixed>|null $subject */
    public function input(string $userHint, ?array $subject, string $fallback = ''): string
    {
        // User input remains source context for topic/retrieval and provenance;
        // it is not reader-facing prose until a separate semantic projection
        // has classified it as factual/editorial material.
        return trim($fallback);
    }
}
