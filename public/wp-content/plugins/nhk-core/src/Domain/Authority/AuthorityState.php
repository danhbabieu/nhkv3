<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Authority;
enum AuthorityState:int { case RETIRED=0; case ACTIVE=1; }
