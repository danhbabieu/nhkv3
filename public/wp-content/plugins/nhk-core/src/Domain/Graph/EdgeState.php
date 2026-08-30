<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
enum EdgeState:int { case RETIRED = 0; case ACTIVE = 1; }
