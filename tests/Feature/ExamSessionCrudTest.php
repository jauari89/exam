<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamPaper;
use App\Models\ExamSeries;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamSessionCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_crud_exam_session_and_list_packages(): void
    {
        $this->seed(RbacSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@example.test')->firstOrFail());
        [$exam, $paper] = $this->examFixture();

        $session = $this->postJson('/api/admin/exam-sessions', [
            'exam_id' => $exam->id,
            'exam_paper_id' => $paper->id,
            'name' => 'Morning Session',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(3)->toIso8601String(),
            'duration_minutes' => 90,
            'mode' => 'strict',
            'status' => 'scheduled',
            'timezone' => 'Asia/Jakarta',
        ])->assertCreated()->json();

        $this->getJson('/api/admin/exam-sessions')->assertOk()->assertJsonPath('data.0.id', $session['id']);

        $this->putJson("/api/admin/exam-sessions/{$session['id']}", [
            'exam_id' => $exam->id,
            'exam_paper_id' => $paper->id,
            'name' => 'Morning Session Updated',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(3)->toIso8601String(),
            'duration_minutes' => 100,
            'mode' => 'tryout',
            'status' => 'active',
            'timezone' => 'Asia/Jakarta',
        ])->assertOk()->assertJsonPath('name', 'Morning Session Updated');

        $this->getJson('/api/admin/exam-packages')->assertOk();

        $this->deleteJson("/api/admin/exam-sessions/{$session['id']}")->assertNoContent();
    }

    public function test_marker_cannot_manage_exam_sessions(): void
    {
        $this->seed(RbacSeeder::class);
        [$exam, $paper] = $this->examFixture();
        $marker = User::factory()->create();
        $marker->roles()->attach(Role::query()->where('name', 'marker')->value('id'));
        Sanctum::actingAs($marker);

        $this->postJson('/api/admin/exam-sessions', [
            'exam_id' => $exam->id,
            'exam_paper_id' => $paper->id,
            'name' => 'Blocked Session',
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(3)->toIso8601String(),
            'duration_minutes' => 90,
            'mode' => 'strict',
        ])->assertForbidden();
    }

    private function examFixture(): array
    {
        $series = ExamSeries::query()->create(['code' => 'SER-SESS', 'title' => 'Series']);
        $exam = Exam::query()->create([
            'exam_series_id' => $series->id,
            'code' => 'SESS',
            'title' => 'Session Exam',
            'mode' => 'strict',
            'default_duration_minutes' => 90,
        ]);
        $paper = ExamPaper::query()->create([
            'exam_id' => $exam->id,
            'code' => 'P1',
            'title' => 'Paper 1',
            'duration_minutes' => 90,
        ]);

        return [$exam, $paper];
    }
}
