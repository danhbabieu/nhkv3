<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Shared\Encoding\{Utf8Contract, Utf8String};

/** Deterministic candidate extractor. It never promotes input to canonical truth. */
final class TextInputInterpreter
{
    public function __construct(private ?StructuredSemanticInterpreter $structured = null)
    {
        $this->structured ??= new StructuredSemanticInterpreter();
    }

    /** @param list<array<string,mixed>> $assets @param list<string> $subjectHints @param array<string,mixed> $metadata @param list<array<string,mixed>> $observations @return array<string,mixed> */
    public function interpret(string $text, array $assets = [], array $subjectHints = [], array $metadata = [], array $observations = []): array
    {
        // The interpreter is the first semantic producer. Do not let PCRE or
        // a later persistence boundary become the first place that detects a
        // malformed string.
        Utf8Contract::assertValid($text, 'semantic.interpretation', 'input.text');
        Utf8Contract::assertValid($assets, 'semantic.interpretation', 'input.assets');
        Utf8Contract::assertValid($subjectHints, 'semantic.interpretation', 'input.subject_hints');
        Utf8Contract::assertValid($metadata, 'semantic.interpretation', 'input.metadata');
        $text = trim($text);
        $structuredObservations = array_values(array_filter(
            array_merge($observations, is_array($metadata['observations'] ?? null) ? $metadata['observations'] : []),
            'is_array',
        ));
        $packet = $this->structured->interpret(UniversalInputEnvelope::fromArray([
            'input_type' => (string) ($metadata['source_kind'] ?? $metadata['input_type'] ?? 'TEXT'),
            'source_identity' => is_array($metadata['source_identity'] ?? null) ? $metadata['source_identity'] : [],
            'raw_input_reference' => $metadata['raw_input_reference'] ?? null,
            'locale' => $metadata['locale'] ?? 'vi-VN',
            'text' => $text,
            'subject_hints' => $subjectHints,
            'metadata' => $metadata,
            'observations' => $structuredObservations,
            'lineage' => is_array($metadata['lineage'] ?? null) ? $metadata['lineage'] : [],
        ]))->toArray();
        $split = preg_split('/(?<=[.!?。！？])\s+/u', $text);
        if ($split === false) throw new \RuntimeException('SEMANTIC_INTERPRETATION_SEGMENTATION_FAILED');
        $sentences = array_values(array_filter(array_map('trim', $split), static fn (string $item): bool => $item !== ''));
        if ($sentences === [] && $text !== '') $sentences = [$text];
        $structuredClaims = $this->structuredClaims($packet);
        $mentions = [];
        $mentionSentences = $structuredClaims === null ? $sentences : array_values(array_filter(array_map(
            static fn (array $candidate): string => trim((string) ($candidate['text'] ?? '')),
            $structuredClaims,
        ), static fn (string $candidate): bool => $candidate !== ''));
        foreach ($mentionSentences as $sentence) {
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
            'source_locators' => [],
            'evidence_excerpts' => [],
            'metadata_signals' => [],
        ];
        foreach ($sentences as $sentence) {
            $classification = $this->structured->classifySegment($sentence);
            if ($classification['class'] === 'SOURCE_LOCATOR') {
                $nonSemantic['source_locators'][] = (string) $classification['value'];
                continue;
            }
            if ($classification['class'] === 'EVIDENCE_EXCERPT') {
                $nonSemantic['evidence_excerpts'][] = $sentence;
                continue;
            }
            if ($classification['class'] === 'MEDIA_METADATA') {
                $nonSemantic['metadata_signals'][] = $sentence;
                continue;
            }
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
            if ($structuredClaims === null) $claims[] = $this->userCandidate($sentence);
        }
        if ($structuredClaims !== null) {
            $claims = $structuredClaims;
            $hasDictionaryCommand = array_values(array_filter((array) ($packet['dictionary_owner_commands'] ?? []), 'is_array')) !== [];
            if (!$hasDictionaryCommand) {
                $explicitClaims = array_values(array_filter($claims, static fn (array $claim): bool => ($claim['candidate_kind'] ?? '') === 'observation'));
                $structuredByKey = [];
                foreach (array_values(array_filter($claims, static fn (array $claim): bool => ($claim['candidate_kind'] ?? '') !== 'observation')) as $claim) {
                    $structuredByKey[$this->claimKey((string) ($claim['text'] ?? ''))][] = $claim;
                }
                $claims = $explicitClaims;
                foreach ($sentences as $sentence) {
                    if (in_array($this->structured->classifySegment($sentence)['class'], ['SOURCE_LOCATOR', 'EVIDENCE_EXCERPT', 'MEDIA_METADATA'], true)) continue;
                    if ($this->sentenceRole($sentence) !== 'claim') continue;
                    $key = $this->claimKey($sentence);
                    if (($structuredByKey[$key] ?? []) !== []) {
                        $claim = array_shift($structuredByKey[$key]);
                        if (($claim['semantic_reason'] ?? '') === 'BOUNDED_FACTUAL_CUE') $claim['text'] = $sentence;
                        $claims[] = $claim;
                        continue;
                    }
                    $claims[] = $this->userCandidate($sentence);
                }
            }
        }
        foreach (['compliance_note', 'compliance_notes'] as $key) {
            $values = is_array($metadata[$key] ?? null) ? $metadata[$key] : [$metadata[$key] ?? null];
            foreach ($values as $value) if (trim((string) $value) !== '') $nonSemantic['compliance_notes'][] = trim((string) $value);
        }
        foreach (['editorial_instruction', 'editorial_instructions'] as $key) {
            $values = is_array($metadata[$key] ?? null) ? $metadata[$key] : [$metadata[$key] ?? null];
            foreach ($values as $value) if (trim((string) $value) !== '') $nonSemantic['editorial_instructions'][] = trim((string) $value);
        }
        foreach (['instructions', 'compliance_notes', 'editorial_instructions', 'source_locators', 'evidence_excerpts', 'metadata_signals'] as $key) $nonSemantic[$key] = array_values(array_unique($nonSemantic[$key]));
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
            'structured_interpretation_packet' => $packet,
            'lexical_spans' => $packet['lexical_spans'],
            'primary_subject_hints' => array_values(array_unique(array_map('strval', $subjectHints))),
            'secondary_subject_hints' => [],
            'entity_mentions' => array_values(array_unique(array_merge(
                $mentions,
                $structuredClaims === null ? array_column($packet['proper_name_spans'], 'term') : [],
            ))),
            'user_claim_candidates' => $claims,
            'media_observations' => $mediaObservations,
            'relation_hints' => [],
            'article_intent' => $articleIntent,
            'non_semantic_context' => $nonSemantic,
            'uncertainty' => $text === '' ? ['EMPTY_INPUT'] : [],
            'asset_count' => count($assets),
        ];
    }

    /** @param array<string,mixed> $packet @return list<array<string,mixed>>|null */
    private function structuredClaims(array $packet): ?array
    {
        $assertions = array_values(array_filter((array) ($packet['semantic_assertions'] ?? []), 'is_array'));
        $commands = array_values(array_filter((array) ($packet['dictionary_owner_commands'] ?? []), 'is_array'));
        if ($assertions === [] && $commands === []) return null;

        $sourceContext = is_array($packet['source_context'] ?? null) ? $packet['source_context'] : [];
        $lineage = is_array($sourceContext['lineage'] ?? null) ? $sourceContext['lineage'] : [];
        $rawReference = $packet['raw_input_reference'] ?? null;
        $claims = [];
        foreach ($assertions as $assertion) {
            $text = trim((string) ($assertion['text'] ?? ''));
            if ($text === '') continue;
            $candidate = $this->userCandidate($text);
            $sourceKind = strtolower(trim((string) ($sourceContext['source_kind'] ?? '')));
            $rawInput = strtoupper(trim((string) ($sourceContext['raw_or_derived'] ?? 'RAW')));
            $assertionProvenance = strtoupper(trim((string) ($assertion['provenance'] ?? '')));
            $classification = $this->structured->classifySegment($text)['class'];
            $isExplicitUserInput = $rawInput !== 'DERIVED'
                && in_array($sourceKind, ['text', 'human_chat', 'chat', 'user_text', 'knowledge', 'knowledge_text', 'knowledge_delta', 'generic'], true)
                && in_array($assertionProvenance, ['', 'UNRESOLVED', 'EXPLICIT_USER_KNOWLEDGE'], true)
                && $classification !== 'UNVERIFIED_INFERENCE';
            $candidate['candidate_kind'] = ($assertion['reason'] ?? '') === 'EXPLICIT_OBSERVATION'
                ? 'observation'
                : ($isExplicitUserInput ? 'user_statement' : 'derived_candidate');
            if ($classification === 'UNVERIFIED_INFERENCE') {
                $candidate['candidate_kind'] = 'derived_candidate';
                $candidate['provenance'] = 'SYSTEM_INFERENCE';
                $candidate['review_required'] = true;
                $candidate['status'] = 'REVIEW_REQUIRED';
            } elseif ($assertionProvenance !== '' && $assertionProvenance !== 'UNRESOLVED') {
                $candidate['provenance'] = $assertionProvenance;
            } elseif ($isExplicitUserInput) {
                $candidate['provenance'] = 'EXPLICIT_USER_KNOWLEDGE';
            }
            if (trim((string) ($assertion['scope'] ?? '')) !== '' && strtoupper((string) $assertion['scope']) !== 'UNRESOLVED') $candidate['scope'] = (string) $assertion['scope'];
            foreach (['facet', 'attributed', 'review_required', 'status', 'source_span', 'segment_id'] as $field) if (array_key_exists($field, $assertion)) $candidate[$field] = $assertion[$field];
            $candidate['semantic_reason'] = (string) ($assertion['reason'] ?? 'STRUCTURED_SEMANTIC_ASSERTION');
            $candidate['raw_input_reference'] = $rawReference;
            $candidate['raw_or_derived'] = (string) ($sourceContext['raw_or_derived'] ?? 'RAW');
            $candidate['lineage'] = $lineage;
            $candidate['source_context'] = $sourceContext;
            $claims[] = $candidate;
        }
        return $claims;
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

    private function claimKey(string $value): string
    {
        $value = trim($value, " \t\n\r.,;:!?。！？");
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    /** @return array<string,mixed> */
    private function userCandidate(string $sentence): array
    {
        Utf8Contract::assertValid($sentence, 'semantic.interpretation', 'user_claim_candidates.text');
        $sentence = Utf8String::trim($sentence, " \t\n\r-*•", 'semantic.interpretation', 'user_claim_candidates.text');
        $lower = function_exists('mb_strtolower') ? mb_strtolower($sentence) : strtolower($sentence);
        $configuration = str_contains($lower, 'côn') || str_contains($lower, 'tiges') || str_contains($lower, 'búa') || str_contains($lower, 'marteaux') || str_contains($lower, 'cấu hình');
        $music = str_contains($lower, 'bài nhạc') || str_contains($lower, 'giai điệu') || str_contains($lower, 'chơi 2 bài');
        $specimenObservation = str_contains($lower, 'chiếc đồng hồ') || str_contains($lower, 'trong video') || str_contains($lower, 'trong ảnh') || str_contains($lower, 'vật thể') || str_contains($lower, 'mẫu này') || str_contains($lower, 'cái này') || str_contains($lower, 'người dùng đánh giá');
        $recognition = str_contains($lower, 'yêu thích') || str_contains($lower, 'nữ hoàng') || str_contains($lower, 'cộng đồng') || str_contains($lower, 'nhận xét');
        $unverifiedInference = $this->structured->classifySegment($sentence)['class'] === 'UNVERIFIED_INFERENCE';
        return [
            'text' => $sentence,
            'candidate_kind' => $unverifiedInference ? 'derived_candidate' : 'user_statement',
            'provenance' => $unverifiedInference ? 'SYSTEM_INFERENCE' : 'EXPLICIT_USER_KNOWLEDGE',
            // Configuration/music are intrinsically variant-scoped. General
            // identity/history/company statements stay unresolved until the
            // canonical subject and evidence context are locked.
            'scope' => $specimenObservation ? 'specimen_observation' : (($configuration || $music) ? 'variant' : 'unspecified'),
            'scope_basis' => $specimenObservation ? 'EXPLICIT_MEDIA_CONTEXT' : (($configuration || $music) ? 'FACET_DEFAULT' : 'UNRESOLVED_CANONICAL_SUBJECT'),
            'facet' => $this->inferredFacet($lower, $configuration, $music, $recognition || $specimenObservation),
            'attributed' => $recognition || $specimenObservation,
            'review_required' => str_contains($lower, 'nữ hoàng') || $unverifiedInference,
            'status' => $unverifiedInference ? 'REVIEW_REQUIRED' : 'CANDIDATE',
        ];
    }

    private function inferredFacet(string $text, bool $configuration, bool $music, bool $recognition): string
    {
        if ($configuration) return 'configuration';
        if ($music) return 'music';
        if (preg_match('/\b(?:thành lập|ra đời|ra mắt|phát hành|sản xuất|năm\s+\d{3,4}|ngày\s+\d{1,2})\b/iu', $text) === 1) return 'chronology';
        if ($recognition || preg_match('/\b(?:sử dụng|được dùng|gắn với|được gọi|được biết|theo quyết định|phát ra|chơi)\b/iu', $text) === 1) return 'recognition';
        return 'identity';
    }
}
