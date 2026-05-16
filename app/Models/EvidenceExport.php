<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvidenceExport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'manifest' => 'array',
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
