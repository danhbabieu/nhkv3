<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Deterministic candidate extractor. It never promotes input to canonical truth. */
final class TextInputInterpreter
{
    /** @param list<array<string,mixed>> $assets @param list<string> $subjectHints @param array<string,mixed> $metadata @return array<string,mixed> */
    public function interpret(string $text, array $assets = [], array $subjectHints = [], array $metadata = []): array
    {
        $text = trim($text);
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?。！？])\s+/u', $text) ?: []), static fn (string $item): bool => $item !== ''));
        if ($sentences === [] && $text !== '') $sentences = [$text];
        $mentions = [];
        foreach ($sentences as $sentence) {
            if (preg_match_all('/(?:[A-ZĐ][\p{L}\d]*(?:[\s-]+[A-ZĐ0-9][\p{L}\d]*){0,4})/u', $sentence, $matches)) {
                foreach ($matches[0] as $mention) {
                    $mention = trim((string) $mention, " \t\n\r.,;:()[]{}\"'");
                    if ($mention !== '' && !in_array($mention, $mentions, true)) $mentions[] = $mention;
                }
            }
        }
        $claims = [];
        $nonSemantic = [
            'instructions' => [],
            'compliance_notes' => [],
            'editorial_instructions' => [],
            'instruction_classes' => [],
        ];
        foreach ($sentences as $sentence) {
            $role = $this->sentenceRole($sentence);
            if ($role === 'compliance') {
                $nonSemantic['compliance_notes'][] = $sentence;
                continue;
            }
            if ($role === 'instruction') {
                $nonSemantic['instructions'][] = $sentence;
                $nonSemantic['instruction_classes'][] = ['text' => $sentence, 'classification' => $this->instructionClass($sentence)];
                continue;
            }
            $claims[] = $this->userCandidate($sentence);
        }
        foreach (['compliance_note', 'compliance_notes'] as $key) {
            $values = is_array($metadata[$key] ?? null) ? $metadata[$key] : [$metadata[$key] ?? null];
            foreach ($values as $value) if (trim((string) $value) !== '') $nonSemantic['compliance_notes'][] = trim((string) $value);
        }
        foreach (['editorial_instruction', 'editorial_instructions'] as $key) {
            $values = is_array($metadata[$key] ?? null) ? $metadata[$key] : [$metadata[$key] ?? null];
            foreach ($values as $value) if (trim((string) $value) !== '') $nonSemantic['editorial_instructions'][] = trim((string) $value);
        }
        foreach (['instructions', 'compliance_notes', 'editorial_instructions'] as $key) $nonSemantic[$key] = array_values(array_unique($nonSemantic[$key]));
        $classes = [];
        foreach ($nonSemantic['instruction_classes'] as $item) {
            $key = (string) ($item['classification'] ?? '') . ':' . (string) ($item['text'] ?? '');
            if ($key !== ':') $classes[$key] = $item;
        }
        $nonSemantic['instruction_classes'] = array_values($classes);
        $articleIntent = implode("\n\n", array_map(static fn (array $candidate): string => (string) $candidate['text'], $claims));
        $mediaObservations = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) continue;
            $observation = trim((string) ($asset['observation'] ?? $asset['observed_text'] ?? ''));
            if ($observation === '') continue;
            $mediaObservations[] = ['text' => $observation, 'provenance' => 'OBSERVED_FROM_MEDIA', 'media_id' => (string) ($asset['media_id'] ?? '')];
        }
        return [
            'primary_subject_hints' => array_values(array_unique(array_map('strval', $subjectHints))),
            'secondary_subject_hints' => [],
            'entity_mentions' => $mentions,
            'user_claim_candidates' => $claims,
            'media_observations' => $mediaObservations,
            'relation_hints' => [],
            'article_intent' => $articleIntent,
            'non_semantic_context' => $nonSemantic,
            'uncertainty' => $text === '' ? ['EMPTY_INPUT'] : [],
            'asset_count' => count($assets),
        ];
    }

    private function sentenceRole(string $sentence): string
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower(trim($sentence)) : strtolower(trim($sentence));
        // These are role markers, not a blacklist of domain claims. A
        // sentence is non-semantic only when it is directing treatment of a
        // claim/source or explicitly describing an evidence/compliance state.
        if (preg_match('/(?:không\s+(?:coi|dùng|sử dụng|nâng|đăng|đưa|project)|chưa\s+có\s+(?:evidence|bằng chứng)|chưa\s+được\s+(?:chứng minh|xác minh)|nhận định\s+(?:so sánh|quảng bá)|claim\s+[^.?!]*\s+(?:chưa|không)\s+có\s+(?:evidence|bằng chứng))/u', $lower) === 1) return 'compliance';
        // A factual imperative is still a semantic assertion. Assertion
        // markers win over an operator verb, e.g. “ghi nhận rằng …”.
        $hasAssertion = preg_match('/(?:\brằng\b|\b(?:là|có|được|sinh|thành lập|đặt tại|nằm ở)\b|\b(?:năm|year)\s+\d{3,4})/u', $lower) === 1;
        if (preg_match('/^(?:không\s+được|đừng|giữ|hãy\s+giữ|hãy\s+(?:reuse|dùng|sửa|đưa|giữ)|reuse\b|vui\s+lòng|please|sửa\b|đưa\b|không\s+dùng|không\s+nâng|không\s+đăng|không\s+coi|không\s+tạo|chỉ\s+là)\b/u', $lower) === 1) return 'instruction';
        if (!$hasAssertion
            && preg_match('/\b(?:bổ sung|cập nhật|hoàn thiện|kiểm tra|xác minh|liên kết|gắn|thêm|đính kèm|đồng bộ|tiếp tục|thực hiện)\b/u', $lower) === 1
            && preg_match('/\b(?:nguồn|hồ sơ|bằng chứng|quan hệ|relation|evidence|source|dữ liệu|metadata|trường|field|website|tài liệu)\b/u', $lower) === 1
        ) return 'instruction';
        return 'claim';
    }

    private function instructionClass(string $sentence): string
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower(trim($sentence)) : strtolower(trim($sentence));
        if (preg_match('/(?:evidence|bằng chứng|tuân thủ|compliance|không\s+được\s+đăng|không\s+được\s+project)/u', $lower) === 1) return 'COMPLIANCE_INSTRUCTION';
        if (preg_match('/(?:reuse|không\s+tạo|sửa\s+(?:semantic|subject|target)|đưa\s+.+\s+vào|không\s+dùng\s+.+\s+thay)/u', $lower) === 1) return 'WORKFLOW_INSTRUCTION';
        return 'EDITORIAL_INSTRUCTION';
    }

    /** @return array<string,mixed> */
    private function userCandidate(string $sentence): array
    {
        $sentence = trim($sentence, " \t\n\r-•*");
        $lower = function_exists('mb_strtolower') ? mb_strtolower($sentence) : strtolower($sentence);
        $configuration = str_contains($lower, 'côn') || str_contains($lower, 'tiges') || str_contains($lower, 'búa') || str_contains($lower, 'marteaux') || str_contains($lower, 'cấu hình');
        $music = str_contains($lower, 'bài nhạc') || str_contains($lower, 'giai điệu') || str_contains($lower, 'chơi 2 bài');
        $specimenObservation = str_contains($lower, 'chiếc đồng hồ') || str_contains($lower, 'trong video') || str_contains($lower, 'trong ảnh') || str_contains($lower, 'vật thể') || str_contains($lower, 'mẫu này') || str_contains($lower, 'cái này') || str_contains($lower, 'người dùng đánh giá');
        $recognition = str_contains($lower, 'yêu thích') || str_contains($lower, 'nữ hoàng') || str_contains($lower, 'cộng đồng') || str_contains($lower, 'nhận xét');
        return [
            'text' => $sentence,
            'candidate_kind' => 'user_statement',
            'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            // Configuration/music are intrinsically variant-scoped. General
            // identity/history/company statements stay unresolved until the
            // canonical subject and evidence context are locked.
            'scope' => $specimenObservation ? 'specimen_observation' : (($configuration || $music) ? 'variant' : 'unspecified'),
            'scope_basis' => $specimenObservation ? 'EXPLICIT_MEDIA_CONTEXT' : (($configuration || $music) ? 'FACET_DEFAULT' : 'UNRESOLVED_CANONICAL_SUBJECT'),
            'facet' => $configuration ? 'configuration' : ($music ? 'music' : ($recognition || $specimenObservation ? 'recognition' : 'identity')),
            'attributed' => $recognition || $specimenObservation,
            'review_required' => str_contains($lower, 'nữ hoàng'),
            'status' => 'CANDIDATE',
        ];
    }
}
