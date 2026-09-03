<?php
declare(strict_types=1);

namespace NHK\Core\Application\Video;

final class YouTubeApiConfiguration
{
    /** @param callable():?string|null $constantReader @param callable():?string|null $environmentReader */
    public function __construct(
        private $constantReader = null,
        private $environmentReader = null,
    ) {
        $this->constantReader ??= static fn (): ?string => defined('NHK_YOUTUBE_API_KEY') ? (string) NHK_YOUTUBE_API_KEY : null;
        $this->environmentReader ??= static fn (): ?string => ($value = getenv('NHK_YOUTUBE_API_KEY')) === false ? null : (string) $value;
    }

    public function value(): ?string
    {
        $constant = trim((string) ($this->constantReader)());
        if ($constant !== '') return $constant;

        $environment = trim((string) ($this->environmentReader)());
        return $environment !== '' ? $environment : null;
    }

    /** @return array{configured:bool,source:'constant'|'environment'|'none'} */
    public function diagnostic(): array
    {
        $constant = trim((string) ($this->constantReader)());
        if ($constant !== '') return ['configured' => true, 'source' => 'constant'];

        $environment = trim((string) ($this->environmentReader)());
        return ['configured' => $environment !== '', 'source' => $environment !== '' ? 'environment' : 'none'];
    }
}
