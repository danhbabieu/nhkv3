<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;

final readonly class VideoIntakePreview
{
    public function __construct(
        public string $videoId,
        public string $operation,
        public int $expectedRevision,
        public array $package,
        public array $warnings = [],
        public array $ambiguities = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['video_id' => $this->videoId, 'operation' => $this->operation, 'expected_revision' => $this->expectedRevision, 'package' => $this->package, 'warnings' => $this->warnings, 'ambiguities' => $this->ambiguities];
    }
}
