<?php

namespace App\Services\Deadlock;

final readonly class DeadlockScenario
{
    public function __construct(
        public int $n,
        public int $m,
        public array $allocation,
        public array $max,
        public array $available,
    ) {}
}
