<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Governance;

enum ConversationalAuthorityPolicy: string
{
    public const VERSION = '1.0.0';
    case OFF = 'OFF';
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    case AUTO_APPROVE_AFTER_OWNER_CONFIRMATION = 'AUTO_APPROVE_AFTER_OWNER_CONFIRMATION';
}
