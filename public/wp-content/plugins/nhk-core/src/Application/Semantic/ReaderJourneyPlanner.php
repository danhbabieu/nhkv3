<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Orders already-selected Knowledge for a reader; it never retrieves or selects Claims. */
final class ReaderJourneyPlanner
{
    private const PROFILES = ['article', 'video', 'image', 'media'];

    public function plan(EditorialContextPack $pack): EditorialPlan
    {
        $profile = strtolower(trim((string) ($pack->profile['profile'] ?? 'article')));
        if (!in_array($profile, self::PROFILES, true)) {
            return new EditorialPlan('review', $profile, $pack->primarySubject, $pack->topic, [], $pack->inputContext, $pack->visualSupport, ['PROFILE_UNSUPPORTED'], []);
        }
        $sections = [$this->opening($profile, $pack->topic)];
        $bucketed = array_merge($pack->readerFacts, $pack->supportingContext, $pack->specimenContext);
        $sourceClaims = $bucketed !== [] ? $bucketed : $pack->selectedClaims;
        $selected = array_values(array_filter($sourceClaims, static fn (mixed $claim): bool => is_array($claim) && ($claim['eligibility'] ?? '') === 'eligible' && ($claim['publicly_composable'] ?? true) === true));
        $maxSections = $profile === 'article' ? 8 : 5;
        $roleCounts = [];
        foreach (array_slice($selected, 0, max(0, $maxSections - 1)) as $index => $claim) {
            $role = strtoupper(trim((string) ($claim['editorial_role'] ?? ($index === 0 ? 'CORE' : 'CONTEXT'))));
            $treatment = strtoupper(trim((string) ($claim['editorial_treatment'] ?? '')));
            if (in_array($treatment, ['SUPPORTING_CONTEXT', 'BACKGROUND_CONTEXT'], true)) $role = 'CONTEXT';
            if ($treatment === 'COMPARATIVE_CONTEXT') $role = 'COMPARISON';
            $claim['editorial_role'] = $role;
            $roleIndex = (int) ($roleCounts[$role] ?? 0);
            $roleCounts[$role] = $roleIndex + 1;
            $unit = is_array($claim['knowledge_unit'] ?? null) ? $claim['knowledge_unit'] : [];
            $unitClaims = array_values(array_filter((array) ($unit['supporting_claims'] ?? []), 'is_array'));
            if ($unitClaims === []) $unitClaims = [$claim];
            $unitClaims = array_map(static function (array $unitClaim) use ($claim): array {
                return array_replace($claim, $unitClaim, ['editorial_role' => $claim['editorial_role'] ?? 'CONTEXT', 'eligibility' => $unitClaim['eligibility'] ?? ($claim['eligibility'] ?? ''), 'publicly_composable' => $unitClaim['publicly_composable'] ?? ($claim['publicly_composable'] ?? true)]);
            }, $unitClaims);
            $sections[] = [
                'id' => $this->sectionId($role, (string) ($claim['claim_id'] ?? $index), $roleIndex),
                'order' => $index + 1,
                'title' => $this->heading($role),
                'purpose' => $this->purpose($role),
                'transition_intent' => $this->transition($role),
                'claim_refs' => array_values(array_map(fn (array $unitClaim): array => $this->reference($unitClaim), $unitClaims)),
                'claims' => $unitClaims,
                'unit_id' => (string) ($unit['unit_id'] ?? ''),
                'aspect_keys' => array_values((array) ($unit['coverage_aspects'] ?? [])),
                'visual_support' => $this->visualFor((string) ($claim['claim_id'] ?? ''), $pack->visualSupport),
            ];
        }
        $status = $pack->status === 'available' ? 'available' : $pack->status;
        $covered = [];
        foreach ($sections as $section) $covered = array_values(array_unique(array_merge($covered, (array) ($section['aspect_keys'] ?? []))));
        return new EditorialPlan($status, $profile, $pack->primarySubject, $pack->topic, $sections, $pack->inputContext, $pack->visualSupport, $pack->blockers, ['selected_count' => count($selected), 'section_count' => count($sections), 'depth' => $profile === 'article' ? 'deep' : 'concise', 'coverage_before' => $pack->diagnostics, 'coverage_after' => $covered, 'uncovered_aspects' => array_values(array_diff($pack->coverageAspects, $covered)), 'journey_depth' => count($sections) - 1]);
    }

    private function opening(string $profile, string $topic): array
    {
        $title = match ($profile) {
            'video' => 'Điều cần chú ý',
            'image', 'media' => 'Điểm nhìn từ hình ảnh',
            default => 'Mở đầu',
        };
        return ['id' => 'opening', 'order' => 0, 'title' => $title, 'purpose' => 'đặt chủ đề và nguồn đầu vào ở vị trí trung tâm', 'transition_intent' => 'đưa người đọc nhanh đến nội dung chính', 'claim_refs' => [], 'claims' => [], 'visual_support' => []];
    }

    private function heading(string $role): string
    {
        return match ($role) {
            'CORE' => 'Điểm chính của chủ đề',
            'IDENTIFICATION' => 'Cách nhận biết',
            'EXPLANATION' => 'Điều cần hiểu thêm',
            'COMPARISON' => 'Những khác biệt đáng chú ý',
            'NEXT_STEP' => 'Bước tìm hiểu tiếp theo',
            default => 'Bối cảnh hữu ích',
        };
    }

    private function purpose(string $role): string
    {
        return match ($role) {
            'CORE' => 'trả lời trực tiếp góc biên tập',
            'IDENTIFICATION' => 'giúp nhận biết đối tượng trong phạm vi được hỗ trợ',
            'EXPLANATION' => 'giải thích ý nghĩa của thông tin chính',
            'NEXT_STEP' => 'mở ra hướng tìm hiểu liên quan',
            default => 'bổ sung bối cảnh cần thiết',
        };
    }

    private function transition(string $role): string
    {
        return $role === 'CORE' ? 'đi thẳng vào câu hỏi của người đọc' : 'mở rộng sau khi nội dung chính đã rõ';
    }

    private function reference(array $claim): array
    {
        return [
            'claim_id' => (string) ($claim['claim_id'] ?? ''),
            'claim_revision' => max(1, (int) ($claim['claim_revision'] ?? 1)),
            'original_subject' => $claim['original_subject'] ?? [],
            'target_subject' => $claim['resolved_primary_subject'] ?? $claim['target_subject'] ?? [],
            'scope' => (string) ($claim['scope'] ?? ''),
            'graph_path' => $claim['graph_path'] ?? [],
            'retrieval_origin' => (string) ($claim['retrieval_origin'] ?? ''),
            'retrieval_tier' => (string) ($claim['retrieval_tier'] ?? 'EXACT'),
            'coverage_kind' => (string) ($claim['coverage_kind'] ?? 'exact'),
            'editorial_treatment' => (string) ($claim['editorial_treatment'] ?? 'DIRECT_FACT'),
            'semantic_context_only' => ($claim['semantic_context_only'] ?? false) === true,
            'editorial_role' => (string) ($claim['editorial_role'] ?? ''),
        ];
    }

    private function visualFor(string $claimId, array $visualSupport): array
    {
        return array_values(array_filter($visualSupport, static fn (mixed $item): bool => is_array($item) && (string) ($item['claim_id'] ?? '') === $claimId));
    }

    private function sectionId(string $role, string $claimId, int $index): string
    {
        return match ($role) {
            'CORE' => $index === 0 ? 'core' : 'core-' . $claimId,
            'EXPLANATION' => $index === 0 ? 'explain' : 'explain-' . $claimId,
            'IDENTIFICATION' => $index === 0 ? 'identification' : 'identification-' . $claimId,
            'NEXT_STEP' => $index === 0 ? 'next-step' : 'next-step-' . $claimId,
            default => strtolower($role) . '-' . $claimId,
        };
    }
}
