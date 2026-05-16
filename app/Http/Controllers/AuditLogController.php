<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;

class AuditLogController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', AuditLog::class);

        return AuditLog::query()->latest('occurred_at')->paginate(50);
    }
}
