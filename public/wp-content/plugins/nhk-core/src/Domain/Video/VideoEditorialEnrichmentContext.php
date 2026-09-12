<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;

use NHK\Core\Shared\Uuid\UuidCodec;

/**
 * Immutable, bounded inputs for Video editorial enrichment.
 *
 * The arrays are read snapshots supplied by canonical callers. This value
 * object does not create Knowledge, Evidence, Graph edges or Authority nodes.
 */
final readonly class VideoEditorialEnrichmentContext
{
    /** @param list<array<string,mixed>> $specimenFacts @param list<array<string,mixed>> $sourceFacts @param list<array<string,mixed>> $canonicalContext @param list<array<string,mixed>> $relatedKnowledge @param list<array<string,mixed>> $relatedEntities */
    public function __construct(
        public array $specimenFacts = [],
        public array $sourceFacts = [],
        public array $canonicalContext = [],
        public array $relatedKnowledge = [],
        public array $relatedEntities = [],
    ) {
    }

    /** @param array<string,mixed> $context */
    public static function fromArray(array $context): self
    {
        return new self(
            self::rows($context['specimen_facts'] ?? []),
            self::rows($context['source_facts'] ?? []),
            self::rows($context['canonical_context'] ?? []),
            self::rows($context['related_knowledge'] ?? [], true),
            self::rows($context['related_entities'] ?? [], true),
        );
    }

    /** @param mixed $value @return list<array<string,mixed>> */
    private static function rows(mixed $value, bool $identityRows = false): array
    {
        if (!is_array($value)) return [];
        $rows = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $text = trim($item);
                if ($text !== '' && !$identityRows) $rows[] = ['text' => $text];
                continue;
            }
            if (!is_array($item)) continue;
            $text = trim((string) ($item['text'] ?? $item['observation'] ?? $item['title'] ?? $item['name'] ?? ''));
            $id = trim((string) ($item['id'] ?? $item['canonical_id'] ?? $item['knowledge_id'] ?? $item['entity_id'] ?? ''));
            if ($identityRows && ($id === '' || !UuidCodec::isValid($id))) continue;
            if (!$identityRows && $text === '') continue;
            $row = $item;
            if ($text !== '') $row['text'] = $text;
            if ($id !== '') $row['id'] = $id;
            $rows[] = $row;
        }
        return array_values($rows);
    }
}
