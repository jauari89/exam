<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'metadata' => 'array',
    ];
}
