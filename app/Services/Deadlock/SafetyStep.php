<?php

namespace App\Services\Deadlock;

final readonly class SafetyStep
{
    /**
     * @param  int  $process  Zero-based position in the original scenario.
     * @param  list<int>  $workBefore
     * @param  list<int>  $need
     * @param  list<int>  $allocation
     * @param  list<int>  $workAfter
     * @param  list<bool>  $finish
     */
    public function __construct(
        public int $number,
        public int $process,
        public array $workBefore,
        public array $need,
        public array $allocation,
        public array $workAfter,
        public array $finish,
    ) {}
}
