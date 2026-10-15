<?php

namespace App\Services\Scheduling;

final readonly class SchedulingProcess
{
    public function __construct(
        public string $id,
        public int $arrivalTime,
        public int $burstTime,
        public int $priority,
    ) {}
}
