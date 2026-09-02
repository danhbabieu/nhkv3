<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;

final readonly class TranscriptPolicy
{
    public const AUTHORIZED_YOUTUBE_TRANSCRIPT = 'AUTHORIZED_YOUTUBE_TRANSCRIPT';
    public const USER_SUPPLIED_TRANSCRIPT = 'USER_SUPPLIED_TRANSCRIPT';
    public const NO_TRANSCRIPT = 'NO_TRANSCRIPT';

    private function __construct(public string $kind, public ?string $language = null, public ?string $text = null, public ?string $retrievedAt = null, public ?string $provenance = null, public ?string $hash = null)
    {
        if (!in_array($kind, [self::AUTHORIZED_YOUTUBE_TRANSCRIPT, self::USER_SUPPLIED_TRANSCRIPT, self::NO_TRANSCRIPT], true)) throw new InvalidVideoReference('Transcript policy is invalid.');
        if ($kind === self::NO_TRANSCRIPT && $text !== null) throw new InvalidVideoReference('A missing transcript cannot contain text.');
        if ($text !== null && strlen($text) > 200000) throw new InvalidVideoReference('Transcript exceeds the allowed bound.');
    }

    public static function none(): self { return new self(self::NO_TRANSCRIPT); }

    public static function authorized(string $text, string $language, string $retrievedAt, string $provenance): self
    {
        return new self(self::AUTHORIZED_YOUTUBE_TRANSCRIPT, trim($language) ?: null, $text, trim($retrievedAt) ?: null, trim($provenance) ?: null, hash('sha256', $text));
    }

    public static function userSupplied(string $text, string $language, string $provenance): self
    {
        return new self(self::USER_SUPPLIED_TRANSCRIPT, trim($language) ?: null, $text, null, trim($provenance) ?: null, hash('sha256', $text));
    }

    public function available(): bool { return $this->text !== null && $this->text !== ''; }
}
