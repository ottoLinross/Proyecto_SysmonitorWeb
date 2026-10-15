<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulingRun extends Model
{
    protected $connection = 'sqlite';

    protected $fillable = ['schema_version', 'mode', 'algorithm', 'quantum', 'input_processes', 'results'];

    protected function casts(): array
    {
        return [
            'schema_version' => 'integer',
            'quantum' => 'integer',
            'input_processes' => 'array',
            'results' => 'array',
        ];
    }
}
