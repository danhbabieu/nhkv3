<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Domain\Governance\ConversationalAuthorityPolicy;

/** WordPress adapter for the Authority-specific policy; global Governance remains authoritative. */
final class WpOptionConversationalAuthorityPolicyStorage
{
    public function __construct(private $getOption = null, private $updateOption = null, private string $optionName = 'nhk_conversational_authority_policy') {}

    public function read(): ConversationalAuthorityPolicy
    {
        $reader = $this->getOption;
        $value = $reader !== null ? $reader($this->optionName, ConversationalAuthorityPolicy::REVIEW_REQUIRED->value) : (function_exists('get_option') ? get_option($this->optionName, ConversationalAuthorityPolicy::REVIEW_REQUIRED->value) : ConversationalAuthorityPolicy::REVIEW_REQUIRED->value);
        return ConversationalAuthorityPolicy::tryFrom((string) $value) ?? ConversationalAuthorityPolicy::REVIEW_REQUIRED;
    }

    public function write(ConversationalAuthorityPolicy $policy): void
    {
        $writer = $this->updateOption;
        $result = $writer !== null ? $writer($this->optionName, $policy->value) : (function_exists('update_option') ? update_option($this->optionName, $policy->value, false) : false);
        if ($result === false) throw new \RuntimeException('CONVERSATIONAL_AUTHORITY_POLICY_SAVE_FAILED');
    }
}
