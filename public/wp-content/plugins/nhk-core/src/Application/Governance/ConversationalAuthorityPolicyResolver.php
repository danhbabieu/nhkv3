<?php
declare(strict_types=1);

namespace NHK\Core\Application\Governance;

use NHK\Core\Domain\Governance\{AutomationMode, ConversationalAuthorityPolicy};

/** Resolves the strictest policy across generic Governance and Authority chat. */
final class ConversationalAuthorityPolicyResolver
{
    public const VERSION = '1.0.0';

    public static function effective(AutomationMode $generic, ConversationalAuthorityPolicy $authority): ConversationalAuthorityPolicy
    {
        if ($authority === ConversationalAuthorityPolicy::OFF) return ConversationalAuthorityPolicy::OFF;
        if ($authority === ConversationalAuthorityPolicy::REVIEW_REQUIRED || $generic === AutomationMode::REVIEW_REQUIRED) return ConversationalAuthorityPolicy::REVIEW_REQUIRED;
        return ConversationalAuthorityPolicy::AUTO_APPROVE_AFTER_OWNER_CONFIRMATION;
    }
}
