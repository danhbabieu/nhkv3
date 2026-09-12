<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Video;

/** A final Video relation cannot be governed without canonical Evidence. */
final class VideoRelationEvidenceRequired extends \InvalidArgumentException
{
    public const ERROR_CODE = 'VIDEO_RELATION_REQUIRES_EVIDENCE';

    public function __construct(string $message = 'Video relation requires evidence.')
    {
        parent::__construct($message);
    }
}
