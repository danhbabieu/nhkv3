<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Semantic\EditorialContextPack;

/** Maps shared transient reader knowledge into the Video owner read model. */
final class VideoEditorialKnowledgeMapper
{
    /** @return array{facts:list<array<string,mixed>>,related_knowledge:list<array<string,mixed>>,claim_dependencies:list<array<string,mixed>>} */
    public function map(EditorialContextPack $pack): array
    {
        $claims = $pack->readerFacts;
        foreach ([$pack->supportingContext, $pack->specimenContext] as $bucket) foreach ($bucket as $claim) $claims[] = $claim;
        if ($claims === []) $claims = $pack->selectedClaims;

        $facts = [];
        $related = [];
        $dependencies = [];
        $seen = [];
        foreach ($claims as $claim) {
            if (!is_array($claim) || ($claim['eligibility'] ?? '') !== 'eligible' || ($claim['publicly_composable'] ?? true) !== true) continue;
            $id = trim((string) ($claim['claim_id'] ?? ''));
            if ($id === '' || isset($seen[$id])) continue;
            $seen[$id] = true;
            $revision = max(1, (int) ($claim['claim_revision'] ?? 1));
            $row = [
                'claim_id' => $id,
                'claim_revision' => $revision,
                'text' => (string) ($claim['text'] ?? $claim['claim_text'] ?? ''),
                'semantic_role' => (string) ($claim['semantic_role'] ?? ''),
                'original_subject' => $claim['original_subject'] ?? [],
                'provenance' => $claim['provenance'] ?? null,
            ];
            $facts[] = $row;
            $related[] = $row;
            $dependencies[] = ['id' => $id, 'revision' => $revision];
        }
        return ['facts' => $facts, 'related_knowledge' => $related, 'claim_dependencies' => $dependencies];
    }
}
