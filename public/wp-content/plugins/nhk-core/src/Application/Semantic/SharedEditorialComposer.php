<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

use NHK\Core\Application\Compliance\PublicEditorialCopyGuard;

/** Shared deterministic composer for Article, Video and Image/Media drafts. */
final class SharedEditorialComposer
{
    public function __construct(private ?PublicEditorialCopyGuard $publicCopyGuard = null)
    {
    }

    public function compose(EditorialPlan $plan): EditorialDraft
    {
        $guard = $this->publicCopyGuard ?? new PublicEditorialCopyGuard();
        $input = trim((string) ($plan->inputContext['raw_input'] ?? ''));
        $title = $this->title($plan->topic, $input);
        $paragraphs = [];
        $claimTexts = [];
        if ($input !== '') {
            $paragraphs[] = $this->openingText($plan->profile, $input);
        } elseif ($plan->topic !== '') {
            $paragraphs[] = $this->openingText($plan->profile, $plan->topic);
        }
        $trace = [];
        $traceFailure = false;
        foreach ($plan->sections as $section) {
            if (($section['id'] ?? '') === 'opening') continue;
            foreach ((array) ($section['claims'] ?? []) as $claim) {
                if (!is_array($claim) || ($claim['eligibility'] ?? '') !== 'eligible' || trim((string) ($claim['claim_id'] ?? '')) === '') {
                    $traceFailure = true;
                    continue;
                }
                $text = trim((string) ($claim['text'] ?? ''));
                if ($text === '') { $traceFailure = true; continue; }
                try { $guard->assertSafe($text); } catch (\Throwable) { $traceFailure = true; continue; }
                $paragraphs[] = (string) ($section['title'] ?? 'Điều cần biết') . ': ' . rtrim($text, '.!?。！？') . '.';
                $claimTexts[] = $text;
                $trace[] = ['claim_id' => (string) $claim['claim_id'], 'claim_revision' => max(1, (int) ($claim['claim_revision'] ?? 1)), 'section_id' => (string) ($section['id'] ?? ''), 'original_subject' => $claim['original_subject'] ?? [], 'graph_path' => $claim['graph_path'] ?? [], 'editorial_role' => (string) ($claim['editorial_role'] ?? ''), 'selection_reason' => (string) ($claim['selection_reason'] ?? '')];
            }
        }
        if ($paragraphs === []) $paragraphs[] = 'Nội dung đang chờ bổ sung dữ liệu biên tập.';
        $body = implode("\n\n", $paragraphs);
        $guard->assertSafe($title); $guard->assertSafe($body);
        $status = $traceFailure ? 'review' : ($trace === [] ? ($input !== '' ? 'sparse_input' : 'review') : 'available');
        $novelty = $this->informationGain($claimTexts, $plan->inputContext);
        return new EditorialDraft($status, $plan->profile, $title, $this->summary($paragraphs[0]), $body, $trace, ['mode' => $trace === [] ? ($input !== '' ? 'sparse_input' : 'no_selected_knowledge') : 'selected_knowledge', 'information_gain' => $novelty, 'visual_support' => $plan->visualSupport, 'traceability' => $traceFailure ? 'FAILED' : 'PASSED']);
    }

    private function openingText(string $profile, string $input): string
    {
        return match ($profile) {
            'video' => $input . ' Video là điểm bắt đầu để theo dõi và đối chiếu các chi tiết liên quan.',
            'image', 'media' => $input . ' Hình ảnh là điểm bắt đầu để quan sát và nhận diện chủ thể.',
            default => $input . ' Nội dung dưới đây tập trung vào điều người đọc cần hiểu về chủ đề này.',
        };
    }

    private function title(string $topic, string $input): string
    {
        $value = trim($topic !== '' ? $topic : $input);
        return function_exists('mb_substr') ? mb_substr($value, 0, 120) : substr($value, 0, 120);
    }

    private function summary(string $first): string
    {
        return function_exists('mb_substr') ? mb_substr(trim($first), 0, 180) : substr(trim($first), 0, 180);
    }

    private function informationGain(array $claimTexts, array $input): float
    {
        if ($claimTexts === []) return 0.0;
        $inputWords = preg_split('/[^\p{L}\p{N}]+/u', function_exists('mb_strtolower') ? mb_strtolower((string) ($input['raw_input'] ?? '')) : strtolower((string) ($input['raw_input'] ?? ''))) ?: [];
        $claimWords = preg_split('/[^\p{L}\p{N}]+/u', function_exists('mb_strtolower') ? mb_strtolower(implode(' ', $claimTexts)) : strtolower(implode(' ', $claimTexts))) ?: [];
        return round(count(array_diff($claimWords, $inputWords)) / max(1, count($claimWords)), 6);
    }
}
