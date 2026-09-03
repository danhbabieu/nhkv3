<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Demo;

use Closure;

final readonly class CutoverPorts
{
    public function __construct(
        public Closure $safety, public Closure $deploy, public Closure $verify, public Closure $preflight,
        public Closure $graph, public Closure $editorial, public Closure $inventory, public Closure $plan,
        public Closure $submit, public Closure $approval, public Closure $eligibility, public Closure $apply,
        public Closure $readback, public Closure $evidence,
    ) {}
}
