<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModerationReview extends Model
{
    protected $guarded = [];

    protected $casts = [
        'final_score' => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];
}
