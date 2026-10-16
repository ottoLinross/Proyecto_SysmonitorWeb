<?php

namespace App\Services\Deadlock;

final readonly class ResourceGraph
{
    /**
     * @param  list<array{id: string, type: string, available?: int, total?: int}>  $nodes
     * @param  list<array{source: string, target: string, type: string, quantity: int}>  $edges
     */
    public function __construct(public array $nodes, public array $edges) {}
}
