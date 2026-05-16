<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoreDetail extends Model
{
    protected $guarded = [];

    protected $casts = [
        'earned_marks' => 'decimal:2',
        'max_marks' => 'decimal:2',
        'metadata' => 'array',
    ];
}
