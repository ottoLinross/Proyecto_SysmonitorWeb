<?php

namespace App\Services\Deadlock;

final readonly class RecoveryChoice
{
    public const TERMINATE = 'terminate';

    public const VICTIM = 'victim';

    public const PREEMPTION = 'preemption';

    public function __construct(public string $strategy, public ?int $process = null) {}
}
