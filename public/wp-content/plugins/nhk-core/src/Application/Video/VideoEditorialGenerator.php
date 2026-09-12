<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

use NHK\Core\Application\Compliance\PublicClaimCopyPolicy;
use NHK\Core\Domain\Video\VideoEditorialEnrichmentContext;

final class VideoEditorialGenerator
{
    public function __construct(private ?VideoEditorialEnrichmentService $enrichment = null) {}

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function generate(array $source, string $userHint = '', string $instruction = '', ?array $resolvedSubject = null, string $editorialTitle = '', string $complianceNote = '', ?array $enrichmentContext = null): array
    {
        $sourceTitle = trim((string) ($source['source_title'] ?? ''));
        $hint = trim($userHint);
        $subjectName = is_array($resolvedSubject) ? trim((string) ($resolvedSubject['name'] ?? '')) : '';
        $neutralTitle = $subjectName !== '' ? $subjectName . ' — Video tham chiếu NHK' : 'Video tham chiếu NHK';
        $copyPolicy = new PublicClaimCopyPolicy();
        // Source title is retained below as provenance only. It is never the
        // default NHK editorial title because platform titles may contain
        // unsupported rankings or absolute claims.
        $requestedTitle = trim($editorialTitle);
        $title = $copyPolicy->safe($requestedTitle, $neutralTitle);
        if ($title === '') $title = $neutralTitle;
        $publicHint = $copyPolicy->containsUnsupportedSuperiority($hint) ? '' : $hint;
        $summary = $publicHint !== ''
            ? 'Một video tham chiếu được NHK đặt trong bối cảnh: ' . $this->truncate($publicHint, 180) . '.'
            : 'NHK giới thiệu video này như một điểm bắt đầu để tìm hiểu đồng hồ cổ qua nguồn tham chiếu đã được chuẩn hóa.';
        $body = $publicHint !== ''
            ? 'Video này được chọn để mở rộng việc tìm hiểu ' . $this->truncate($publicHint, 260) . '. Nội dung NHK giữ vai trò giải thích và liên kết ngữ cảnh, không thay thế nguồn video.'
            : 'Video này được trình bày như một nguồn tham chiếu bên ngoài trong hệ thống khám phá của NHK. Các nhận định kỹ thuật chỉ được bổ sung khi có nguồn hoặc quan hệ ngữ nghĩa phù hợp.';
        $context = [];
        if ($hint !== '') $context[] = ['text' => $hint, 'provenance' => 'USER_HINT'];
        if ($sourceTitle !== '') $context[] = ['text' => $sourceTitle, 'provenance' => 'SOURCE_FACT'];
        $base = [
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
            'why_this_matters' => 'Giúp người đọc bắt đầu từ nội dung video rồi tiếp tục tới các đối tượng và tri thức có quan hệ được kiểm chứng.',
            'context' => $context,
            'facts' => $sourceTitle === '' ? [] : [['text' => $sourceTitle, 'provenance' => 'SOURCE_FACT']],
            'related_knowledge' => [],
            'compliance_context' => ['note' => trim($complianceNote), 'rewrite_applied' => $requestedTitle !== '' && $title !== $requestedTitle],
        ];
        if ($enrichmentContext === null) return $base;
        return array_merge($base, ($this->enrichment ?? new VideoEditorialEnrichmentService())->enrich($base, VideoEditorialEnrichmentContext::fromArray($enrichmentContext))['editorial']);
    }

    private function firstSentence(string $value): string { return trim((string) preg_split('/[.!?\n]/', $value, 2)[0]); }
    private function truncate(string $value, int $limit): string { return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit); }
}
