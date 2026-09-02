<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class VideoEditorialGenerator
{
    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function generate(array $source, string $userHint = '', string $instruction = ''): array
    {
        $sourceTitle = trim((string) ($source['source_title'] ?? ''));
        $hint = trim($userHint);
        $titleSubject = $sourceTitle !== '' ? $sourceTitle : ($hint !== '' ? $this->firstSentence($hint) : 'video đồng hồ cổ');
        $title = 'Khám phá ' . $this->truncate($titleSubject, 100) . ' cùng NHK';
        $summary = $hint !== ''
            ? 'Một video tham chiếu được NHK đặt trong bối cảnh: ' . $this->truncate($hint, 180) . '.'
            : 'NHK giới thiệu video này như một điểm bắt đầu để tìm hiểu đồng hồ cổ qua nguồn tham chiếu đã được chuẩn hóa.';
        $body = $hint !== ''
            ? 'Video này được chọn để mở rộng việc tìm hiểu ' . $this->truncate($hint, 260) . '. ' . ($instruction !== '' ? 'Định hướng biên tập: ' . $this->truncate($instruction, 180) . '.' : 'Nội dung NHK giữ vai trò giải thích và liên kết ngữ cảnh, không thay thế nguồn video.')
            : 'Video này được trình bày như một nguồn tham chiếu bên ngoài trong hệ thống khám phá của NHK. Các nhận định kỹ thuật chỉ được bổ sung khi có nguồn hoặc quan hệ ngữ nghĩa phù hợp.';
        $context = [];
        if ($hint !== '') $context[] = ['text' => $hint, 'provenance' => 'USER_HINT'];
        if ($sourceTitle !== '') $context[] = ['text' => $sourceTitle, 'provenance' => 'SOURCE_FACT'];
        return [
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
            'why_this_matters' => 'Giúp người đọc bắt đầu từ nội dung video rồi tiếp tục tới các đối tượng và tri thức có quan hệ được kiểm chứng.',
            'context' => $context,
            'facts' => $sourceTitle === '' ? [] : [['text' => $sourceTitle, 'provenance' => 'SOURCE_FACT']],
            'related_knowledge' => [],
        ];
    }

    private function firstSentence(string $value): string { return trim((string) preg_split('/[.!?\n]/', $value, 2)[0]); }
    private function truncate(string $value, int $limit): string { return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit); }
}
