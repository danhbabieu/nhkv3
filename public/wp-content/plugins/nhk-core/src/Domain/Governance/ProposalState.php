<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Governance;

enum ProposalState: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case SUPERSEDED = 'superseded';
    case APPLIED = 'applied';
}
