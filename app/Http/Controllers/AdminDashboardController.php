<?php

namespace App\Http\Controllers;

use App\Services\AdminDashboardService;
use Illuminate\Support\Facades\Gate;

class AdminDashboardController extends Controller
{
    public function show(AdminDashboardService $dashboard)
    {
        Gate::authorize('view-dashboard');

        return response()->json($dashboard->overview());
    }
}
