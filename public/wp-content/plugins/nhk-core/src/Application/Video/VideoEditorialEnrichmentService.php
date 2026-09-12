<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Compliance\PublicClaimCopyPolicy;
use NHK\Core\Domain\Video\{VideoEditorialEnrichmentContext, VideoEditorialQuality};

/**
 * Builds a bounded editorial projection from immutable, scoped read snapshots.
 * It never creates or mutates a semantic record.
 */
final class VideoEditorialEnrichmentService
{
    public function __construct(private ?VideoEditorialQualityPolicy $quality = null) {}

    /** @param array<string,mixed> $base @return array<string,mixed> */
    public function enrich(array $base, VideoEditorialEnrichmentContext $context): array
    {
        $copy = new PublicClaimCopyPolicy();
        $title = trim((string) ($base['title'] ?? ''));
        $title = $copy->safe($title, 'Video tham chiếu NHK');
        $subject = $this->firstText($context->canonicalContext) ?: $title;
        $specimen = $this->texts($context->specimenFacts);
        $source = $this->texts($context->sourceFacts);
        $canonical = $this->texts($context->canonicalContext);
        $paragraphs = ['Video này ghi lại đúng hiện vật được nêu trong nguồn tham chiếu; các mô tả dưới đây chỉ áp dụng cho phạm vi của bản ghi này.'];
        if ($specimen !== []) $paragraphs[] = 'Trên chính hiện vật, nguồn mô tả: ' . implode('; ', $specimen) . '. Đây là thông tin quan sát/nhận diện của chiếc xuất hiện trong video, không phải đặc tính mặc định của toàn bộ dòng sản phẩm.';
        if ($source !== []) $paragraphs[] = 'Theo metadata nguồn, video được giới thiệu với các thông tin: ' . implode('; ', $source) . '. NHK giữ lớp này như SOURCE_FACT và không biến cách diễn đạt marketing thành kết luận phổ quát.';
        if ($canonical !== []) $paragraphs[] = 'Trong bối cảnh tri thức NHK, chủ thể liên quan được nhận diện là ' . $subject . '. Bối cảnh canonical giúp đặt hiện vật vào đúng Variant/Model hoặc thực thể liên quan, nhưng không thay thế bằng chứng riêng của chiếc trong video.';
        $paragraphs[] = 'Khi đối chiếu hoặc sưu tầm, nên tách đặc điểm nhìn thấy trên bản ghi khỏi thông tin chung của thực thể canonical; các điểm chưa có Source/Evidence phù hợp vẫn cần được xem là nội dung chờ rà soát.';
        if ($context->relatedKnowledge !== [] || $context->relatedEntities !== []) $paragraphs[] = 'Người đọc có thể tiếp tục từ các Knowledge và thực thể canonical liên quan bên dưới để so sánh thuật ngữ, cấu hình hoặc bối cảnh đã được NHK ghi nhận.';

        $summary = 'Video này ghi lại ' . ($subject !== '' ? $subject : 'một hiện vật đồng hồ') . ' qua một nguồn tham chiếu cụ thể. Nội dung phân biệt dữ kiện của chính hiện vật, thông tin từ nguồn và bối cảnh canonical để việc nhận diện không bị suy rộng quá mức.';
        $body = implode("\n\n", $paragraphs);
        $facts = array_merge($this->tagged($context->specimenFacts, 'SPECIMEN'), $this->tagged($context->sourceFacts, 'SOURCE_FACT'));
        $editorial = [
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
            'context' => array_merge((array) ($base['context'] ?? []), $this->tagged($context->canonicalContext, 'CANONICAL_CONTEXT')),
            'facts' => array_merge((array) ($base['facts'] ?? []), $facts),
            'why_this_matters' => 'Video tạo một điểm đối chiếu cụ thể giữa hiện vật, nguồn tham chiếu và tri thức canonical; nhờ đó người đọc có thể nhận diện đúng phạm vi thông tin trước khi đi sâu vào các Knowledge liên quan.',
            'related_knowledge' => $this->identityRows($context->relatedKnowledge),
            'related_entities' => $this->identityRows($context->relatedEntities),
        ];
        $quality = ($this->quality ?? new VideoEditorialQualityPolicy())->evaluate($editorial, $context);
        return ['editorial' => $editorial, 'seo' => ['title' => $title, 'description' => $summary], 'enrichment_context' => $this->contextArray($context), 'content_quality' => $quality->toArray()];
    }

    /** @param list<array<string,mixed>> $rows @return list<string> */
    private function texts(array $rows): array { return array_values(array_filter(array_map(static fn (array $row): string => trim((string) ($row['text'] ?? $row['observation'] ?? $row['title'] ?? $row['name'] ?? '')), $rows), static fn (string $text): bool => $text !== '')); }
    /** @param list<array<string,mixed>> $rows */
    private function firstText(array $rows): string { return $this->texts($rows)[0] ?? ''; }
    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function tagged(array $rows, string $provenance): array { return array_map(static fn (array $row): array => array_merge($row, ['provenance' => $provenance]), $rows); }
    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function identityRows(array $rows): array { return array_values(array_map(static fn (array $row): array => array_filter($row, static fn (mixed $value): bool => $value !== null && $value !== ''), $rows)); }
    /** @return array<string,mixed> */
    private function contextArray(VideoEditorialEnrichmentContext $context): array { return ['specimen_facts' => $context->specimenFacts, 'source_facts' => $context->sourceFacts, 'canonical_context' => $context->canonicalContext, 'related_knowledge' => $context->relatedKnowledge, 'related_entities' => $context->relatedEntities]; }
}
