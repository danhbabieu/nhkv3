<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Application\Semantic\StructuredSemanticInterpreter;
use NHK\Core\Domain\Knowledge\KnowledgeClaim;

/** Public projection guard for claims that are actually workflow/source input. */
final class PublicKnowledgeClaimPolicy
{
    public function __construct(private ?StructuredSemanticInterpreter $interpreter = null)
    {
        $this->interpreter ??= new StructuredSemanticInterpreter();
    }

    public function allows(KnowledgeClaim $claim): bool
    {
        $classification = $this->interpreter->classifySegment($claim->claimText)['class'] ?? '';
        if (in_array($classification, ['SOURCE_LOCATOR', 'OPERATIONAL_INSTRUCTION'], true)) return false;

        $metadata = is_array($claim->provenance['metadata'] ?? null) ? $claim->provenance['metadata'] : [];
        if (in_array(strtoupper((string) ($metadata['origin'] ?? $claim->provenance['origin'] ?? '')), ['SYSTEM_WORKFLOW', 'AI_GENERATED', 'DERIVED_TRANSCRIPTION'], true)) return false;
        return preg_match('/\b(?:resolve|parser|parse|normalize|standardize|hệ thống|parser nhận diện|được tạo|được sinh|chuẩn hóa|mô hình nhận diện|ai xác định)\b/ui', $claim->claimText) !== 1;
    }
}
