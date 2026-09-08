<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Domain\Projection\ProjectedClaim;

final class SeoProjectionBuilder
{
    public const TEMPLATE_REVISION = 1;

    /** @param array<string,mixed> $ledger @return array<string,mixed> */
    public function build(array $ledger, string $canonicalUrl, string $h1): array
    {
        $sections = [];
        foreach ((array) ($ledger['sections'] ?? []) as $section) {
            $key = (string) ($section['key'] ?? '');
            $content = [];
            foreach ((array) ($section['claims'] ?? []) as $claim) {
                if (!is_array($claim)) continue;
                $text = trim((string) ($claim['display_text'] ?? ''));
                if ($text === '') continue;
                $status = strtoupper((string) ($claim['status'] ?? 'APPROVED'));
                if ($status === 'DISPUTED') $text = 'Các nguồn hiện ghi nhận: ' . $text . '. Nội dung này vẫn đang được đối chiếu.';
                elseif ($status === 'UNCERTAIN') $text = 'Một ghi nhận cần kiểm chứng cho biết: ' . $text;
                $content[] = '<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            }
            $sections[$key] = ['key' => $key, 'label' => (string) ($section['label'] ?? $key), 'content' => implode('', $content), 'input_hash' => hash('sha256', implode('|', array_map(static fn (array $claim): string => (string) ($claim['claim_uuid'] ?? '') . ':' . (string) ($claim['display_text'] ?? ''), (array) ($section['claims'] ?? []))))];
        }
        return ['node_uuid' => (string) ($ledger['node_uuid'] ?? ''), 'h1' => $h1, 'canonical_url' => $canonicalUrl, 'sections' => $sections, 'input_hash' => hash('sha256', json_encode([$canonicalUrl, $h1, $sections, self::TEMPLATE_REVISION], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'template_revision' => self::TEMPLATE_REVISION];
    }
}
