<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeadlockSimulation extends Model
{
    protected $connection = 'sqlite';

    // Persist only explicitly assigned, validated scenario fields.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'n' => 'integer',
            'm' => 'integer',
            'allocation' => 'array',
            'max' => 'array',
            'available' => 'array',
        ];
    }
}
