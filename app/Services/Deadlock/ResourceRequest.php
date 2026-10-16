<?php

namespace App\Services\Deadlock;

final readonly class ResourceRequest
{
    /** @param list<int> $vector */
    public function __construct(public int $process, public array $vector) {}
}
