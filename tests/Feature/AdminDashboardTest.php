<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_operations_dashboard(): void
    {
        $this->seed(RbacSeeder::class);

        Sanctum::actingAs(User::query()->where('email', 'admin@example.test')->firstOrFail());

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'server_time',
                'summary' => [
                    'series',
                    'exams',
                    'sessions',
                    'active_sessions',
                    'candidates',
                    'question_banks',
                    'question_bank_items',
                    'attempts',
                    'in_progress_attempts',
                    'pending_manual_answers',
                    'open_incidents',
                ],
                'attempt_statuses',
                'active_sessions',
                'recent_attempts',
                'recent_events',
                'recent_incidents',
                'recent_audit_logs',
            ]);
    }

    public function test_proctor_can_view_dashboard_but_marker_cannot(): void
    {
        $this->seed(RbacSeeder::class);

        $proctor = User::factory()->create();
        $proctor->roles()->attach(Role::query()->where('name', 'proctor')->value('id'));
        Sanctum::actingAs($proctor);
        $this->getJson('/api/admin/dashboard')->assertOk();

        $marker = User::factory()->create();
        $marker->roles()->attach(Role::query()->where('name', 'marker')->value('id'));
        Sanctum::actingAs($marker);
        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }
}
