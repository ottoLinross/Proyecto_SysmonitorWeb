<?php

namespace App\Services\Deadlock;

final readonly class ResourceRequestResult
{
    public const INVALID = 'invalid';

    public const WAIT = 'wait';

    public const GRANTED = 'granted';

    public const UNSAFE = 'unsafe';

    public function __construct(
        public ResourceRequest $request,
        public string $decision,
        public string $explanation,
        public ?DeadlockScenario $simulated = null,
        public ?SafetyResult $safety = null,
    ) {}
}
