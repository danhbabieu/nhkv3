<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Knowledge;

final class DependencyValidationException extends KnowledgeException
{
    public function __construct(public readonly string $errorCode, public readonly string $field, public readonly string $receivedId, string $message)
    {
        parent::__construct($message);
    }

    /** @return array{code:string,field:string,received_id:string,expected:string} */
    public function toStructuredError(): array
    {
        return ['code' => $this->errorCode, 'field' => $this->field, 'received_id' => $this->receivedId, 'expected' => 'active canonical entity UUID'];
    }
}
