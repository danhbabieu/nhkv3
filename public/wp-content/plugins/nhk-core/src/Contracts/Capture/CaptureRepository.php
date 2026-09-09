<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Capture;

use NHK\Core\Domain\Capture\CaptureRecord;

interface CaptureRepository
{
    public function findByIdempotencyKey(string $key): ?CaptureRecord;
    public function create(CaptureRecord $record): CaptureRecord;
    public function save(CaptureRecord $record): CaptureRecord;
}
