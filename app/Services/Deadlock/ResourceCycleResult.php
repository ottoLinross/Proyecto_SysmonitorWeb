<?php

namespace App\Services\Deadlock;

final readonly class ResourceCycleResult
{
    public const NO_REQUEST = 'no_request';

    public const ACYCLIC = 'acyclic';

    public const CYCLE = 'cycle';

    /** @param list<string> $path Closed directed walk when a cycle is found; otherwise empty. */
    public function __construct(public string $status, public array $path = []) {}
}
