<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedProcess extends Model
{
    protected $fillable = [
        'pid', 'process_type', 'command_label', 'owner_uid',
        'start_time_ticks', 'status', 'launched_at',
    ];

    protected function casts(): array
    {
        return [
            'pid' => 'integer',
            'owner_uid' => 'integer',
            'start_time_ticks' => 'integer',
            'launched_at' => 'datetime',
        ];
    }
}
