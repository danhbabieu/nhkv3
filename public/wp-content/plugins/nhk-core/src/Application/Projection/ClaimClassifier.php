<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

use NHK\Core\Domain\Knowledge\KnowledgeClaim;
use NHK\Core\Domain\Projection\ClaimProjectionCategory;

final class ClaimClassifier
{
    public function classify(KnowledgeClaim $claim): string
    {
        $metadata = $claim->provenance['metadata'] ?? [];
        if (is_array($metadata)) {
            foreach (['projection_category', 'category', 'claim_category'] as $key) {
                $value = trim((string) ($metadata[$key] ?? ''));
                if (ClaimProjectionCategory::isValid($value)) return $value;
            }
        }

        $type = strtolower($claim->claimType);
        $text = strtolower($claim->claimText);
        if ($this->contains($text, ['côn', 'búa', 'chuông', 'strike', 'bản nhạc', 'giai điệu', 'music'])) return 'music_and_strike';
        if (in_array($type, ['history', 'provenance'], true)) return $type;
        if (in_array($type, ['specification', 'technical'], true)) {
            if ($this->contains($text, ['côn', 'kim', 'mặt số', 'dial', 'hand'])) return $this->contains($text, ['côn', 'búa', 'chuông', 'strike']) ? 'music_and_strike' : 'dial_and_hands';
            if ($this->contains($text, ['mm', 'cm', 'kích thước', 'dimension'])) return 'dimension';
            if ($this->contains($text, ['vật liệu', 'material', 'đồng', 'thép'])) return 'material';
            if ($this->contains($text, ['cấu hình', 'config', 'lắp', 'sử dụng'])) return 'configuration';
            return 'mechanism';
        }
        if ($this->contains($text, ['nhận diện', 'phân biệt', 'identify', 'recognition'])) return 'identification_rule';
        if ($this->contains($text, ['tranh luận', 'mâu thuẫn', 'dispute', 'không thống nhất'])) return 'dispute';
        if ($this->contains($text, ['chất âm', 'âm thanh', 'sound', 'âm sắc', 'nghe'])) return 'user_experience';
        if ($this->contains($text, ['lịch sử', 'history', 'ra đời', 'sản xuất'])) return 'history';
        if ($this->contains($text, ['so sánh', 'comparison', 'khác với'])) return 'comparison';
        if ($this->contains($text, ['cấu hình', 'configuration', 'lắp đặt', 'trang bị'])) return 'configuration';
        if ($this->contains($text, ['thành phần', 'component', 'bộ phận'])) return 'component';
        if ($this->contains($text, ['nhận dạng', 'identity', 'là một'])) return 'identity';
        return 'other';
    }

    /** @param list<string> $needles */
    private function contains(string $text, array $needles): bool
    {
        foreach ($needles as $needle) if (str_contains($text, $needle)) return true;
        return false;
    }
}
