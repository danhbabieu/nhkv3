<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Governance;

enum ProposalState: string
{
    case DRAFT = 'draft';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case APPLIED = 'applied';
}
