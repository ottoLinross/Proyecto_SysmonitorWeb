<?php

namespace App\Services\Deadlock;

final readonly class SafetyResult
{
    /**
     * Process positions use zero-based indices in the original scenario.
     *
     * @param  list<list<int>>  $need
     * @param  list<int>|null  $safeSequence
     * @param  list<int>  $completed
     * @param  list<int>  $unfinished
     * @param  list<int>  $work
     * @param  list<bool>  $finish
     */
    public function __construct(
        public array $need,
        public bool $isSafe,
        public ?array $safeSequence,
        public array $completed,
        public array $unfinished,
        public array $work,
        public array $finish,
    ) {}
}
