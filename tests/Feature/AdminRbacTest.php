<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_marker_cannot_manage_exam_series_but_admin_can(): void
    {
        $this->seed(RbacSeeder::class);

        $marker = User::factory()->create();
        $marker->roles()->attach(Role::query()->where('name', 'marker')->value('id'));

        Sanctum::actingAs($marker);
        $this->getJson('/api/admin/exam-series')->assertForbidden();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/exam-series')->assertOk();
    }
}
