<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Capture;

enum CapturePurpose: string
{
    case EDITORIAL = 'EDITORIAL';
    case AUTHORITY = 'AUTHORITY';
    case MIXED = 'MIXED';
}
