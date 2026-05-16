<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamPaper;
use App\Models\ExamSeries;
use App\Models\ExamSession;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuestionBankPackageBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_import_question_bank_and_build_shuffled_package(): void
    {
        $this->seed(RbacSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@example.test')->firstOrFail());
        $paper = $this->paperFixture();

        $bank = $this->postJson('/api/admin/question-banks', [
            'code' => 'QB-MIXED',
            'title' => 'Mixed Question Bank',
            'subject' => 'Physics',
            'level' => 'Cambridge',
        ])->assertCreated()->json();

        $this->postJson("/api/admin/question-banks/{$bank['id']}/import", [
            'mode' => 'replace',
            'questions' => $this->questions(),
        ])->assertOk()->assertJson([
            'created' => 10,
            'updated' => 0,
            'total_items' => 10,
        ]);

        $package = $this->postJson("/api/admin/question-banks/{$bank['id']}/build-package", [
            'exam_paper_id' => $paper->id,
            'question_count' => 10,
            'difficulty_mix' => ['easy' => 4, 'medium' => 4, 'hard' => 2],
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'strict_mode' => true,
        ])->assertCreated()->json();

        $this->assertCount(10, $package['questions']);
        $this->assertDatabaseHas('exam_packages', [
            'id' => $package['id'],
            'exam_paper_id' => $paper->id,
            'strict_mode' => true,
        ]);
        $this->assertDatabaseCount('questions', 10);
        $this->assertDatabaseCount('question_options', 15);
        $this->assertSame('question_bank', $package['source_payload']['metadata']['source']);
        $this->assertSame('QB-MIXED', $package['source_payload']['metadata']['question_bank_code']);
    }

    public function test_objective_import_rejects_questions_without_correct_option(): void
    {
        $this->seed(RbacSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@example.test')->firstOrFail());

        $bank = $this->postJson('/api/admin/question-banks', [
            'code' => 'QB-BAD',
            'title' => 'Bad Question Bank',
        ])->assertCreated()->json();

        $this->postJson("/api/admin/question-banks/{$bank['id']}/import", [
            'questions' => [[
                'external_id' => 'BAD-Q1',
                'type' => 'objective',
                'difficulty' => 'easy',
                'stem' => ['text' => 'No correct answer here.'],
                'options' => [
                    ['external_id' => 'A', 'content' => ['text' => 'A'], 'is_correct' => false],
                    ['external_id' => 'B', 'content' => ['text' => 'B'], 'is_correct' => false],
                ],
            ]],
        ])->assertStatus(422);
    }

    public function test_admin_can_import_question_bank_from_csv_and_publish_specific_package_to_session(): void
    {
        $this->seed(RbacSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@example.test')->firstOrFail());
        $paper = $this->paperFixture();
        $session = ExamSession::query()->create([
            'exam_id' => $paper->exam_id,
            'exam_paper_id' => $paper->id,
            'name' => 'CSV Session',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'duration_minutes' => 60,
            'mode' => 'strict',
        ]);

        $bank = $this->postJson('/api/admin/question-banks', [
            'code' => 'QB-CSV',
            'title' => 'CSV Question Bank',
        ])->assertCreated()->json();

        $csv = "external_id,type,topic,difficulty,max_marks,question,option_a,option_b,option_c,correct_answer\n"
            ."CSV-Q1,MCQ,Topic,easy,1,Question one,Right,Wrong,Maybe,A\n"
            ."CSV-Q2,TrueFalse,Topic,easy,1,The sky is blue,,,,True\n";

        $this->post("/api/admin/question-banks/{$bank['id']}/import-file", [
            'file' => UploadedFile::fake()->createWithContent('questions.csv', $csv),
        ])->assertCreated()->assertJsonPath('created', 2);

        $package = $this->postJson("/api/admin/question-banks/{$bank['id']}/build-package", [
            'exam_paper_id' => $paper->id,
            'question_count' => 2,
            'shuffle_questions' => false,
            'shuffle_options' => false,
        ])->assertCreated()->json();

        $this->postJson("/api/admin/exam-packages/{$package['id']}/publish-session", [
            'exam_session_id' => $session->id,
            'status' => 'active',
        ])->assertOk()->assertJsonPath('settings.published_package_id', $package['id']);
    }

    public function test_admin_can_upload_question_bank_media_and_attach_it_to_question(): void
    {
        $this->seed(RbacSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@example.test')->firstOrFail());

        $bank = $this->postJson('/api/admin/question-banks', [
            'code' => 'QB-MEDIA',
            'title' => 'Media Question Bank',
        ])->assertCreated()->json();

        $upload = $this->post("/api/admin/question-banks/{$bank['id']}/media", [
            'file' => UploadedFile::fake()->image('diagram.png', 80, 60),
        ])->assertCreated()
            ->assertJsonPath('file_name', 'diagram.png')
            ->assertJsonPath('mime_type', 'image/png')
            ->json();

        $this->assertFileExists(public_path(ltrim($upload['url'], '/')));

        $this->postJson("/api/admin/question-banks/{$bank['id']}/questions", [
            'external_id' => 'MEDIA-Q1',
            'type' => 'objective',
            'difficulty' => 'easy',
            'max_marks' => 1,
            'stem' => ['text' => 'Use the diagram to answer.', 'image' => $upload['url']],
            'metadata' => [
                'question_format' => 'objective',
                'cognitive_level' => 'hots',
                'bloom_level' => 'analyze',
            ],
            'options' => [
                ['external_id' => 'A', 'content' => ['text' => 'Correct'], 'is_correct' => true, 'marks' => 1],
                ['external_id' => 'B', 'content' => ['text' => 'Wrong'], 'is_correct' => false, 'marks' => 0],
            ],
        ])->assertCreated()
            ->assertJsonPath('stem.image', $upload['url'])
            ->assertJsonPath('metadata.cognitive_level', 'hots');

        File::deleteDirectory(public_path('question-bank-media/qb-media'));
    }

    public function test_question_bank_media_upload_rejects_svg_files(): void
    {
        $this->seed(RbacSeeder::class);
        Sanctum::actingAs(User::query()->where('email', 'admin@example.test')->firstOrFail());

        $bank = $this->postJson('/api/admin/question-banks', [
            'code' => 'QB-SVG',
            'title' => 'SVG Safety Bank',
        ])->assertCreated()->json();

        $this->withHeader('Accept', 'application/json')->post("/api/admin/question-banks/{$bank['id']}/media", [
            'file' => UploadedFile::fake()->createWithContent('diagram.svg', '<svg><script>alert(1)</script></svg>'),
        ])->assertStatus(422);
    }

    public function test_marker_cannot_manage_question_bank(): void
    {
        $this->seed(RbacSeeder::class);

        $marker = User::factory()->create();
        $marker->roles()->attach(Role::query()->where('name', 'marker')->value('id'));
        Sanctum::actingAs($marker);

        $this->getJson('/api/admin/question-banks')->assertForbidden();
    }

    private function paperFixture(): ExamPaper
    {
        $series = ExamSeries::query()->create(['code' => 'SER-QB', 'title' => 'Series']);
        $exam = Exam::query()->create([
            'exam_series_id' => $series->id,
            'code' => 'SCI',
            'title' => 'Science',
            'mode' => 'strict',
            'default_duration_minutes' => 60,
        ]);

        return ExamPaper::query()->create([
            'exam_id' => $exam->id,
            'code' => 'P1',
            'title' => 'Paper 1',
            'duration_minutes' => 60,
        ]);
    }

    private function questions(): array
    {
        return [
            $this->objective('Q1', 'easy'),
            $this->objective('Q2', 'easy'),
            $this->objective('Q3', 'easy'),
            $this->objective('Q4', 'easy'),
            $this->objective('Q5', 'medium'),
            $this->objective('Q6', 'medium'),
            $this->checkbox('Q7', 'medium'),
            $this->numerical('Q8', 'medium'),
            $this->essay('Q9', 'hard'),
            $this->structured('Q10', 'hard'),
        ];
    }

    private function objective(string $id, string $difficulty): array
    {
        return [
            'external_id' => $id,
            'type' => 'objective',
            'difficulty' => $difficulty,
            'topic' => 'Measurement',
            'max_marks' => 1,
            'stem' => ['text' => "Objective $id"],
            'options' => [
                ['external_id' => 'A', 'content' => ['text' => 'Correct'], 'is_correct' => true, 'marks' => 1],
                ['external_id' => 'B', 'content' => ['text' => 'Distractor'], 'is_correct' => false, 'marks' => 0],
            ],
        ];
    }

    private function checkbox(string $id, string $difficulty): array
    {
        return [
            'external_id' => $id,
            'type' => 'checkbox',
            'difficulty' => $difficulty,
            'topic' => 'Measurement',
            'max_marks' => 2,
            'stem' => ['text' => "Checkbox $id"],
            'options' => [
                ['external_id' => 'A', 'content' => ['text' => 'Correct 1'], 'is_correct' => true, 'marks' => 1],
                ['external_id' => 'B', 'content' => ['text' => 'Correct 2'], 'is_correct' => true, 'marks' => 1],
                ['external_id' => 'C', 'content' => ['text' => 'Wrong'], 'is_correct' => false, 'marks' => 0],
            ],
        ];
    }

    private function numerical(string $id, string $difficulty): array
    {
        return [
            'external_id' => $id,
            'type' => 'numerical',
            'difficulty' => $difficulty,
            'topic' => 'Calculation',
            'max_marks' => 2,
            'stem' => ['text' => "Numerical $id"],
            'correct_answer' => ['value' => 42],
            'validation_rules' => ['tolerance' => 0.1],
        ];
    }

    private function essay(string $id, string $difficulty): array
    {
        return [
            'external_id' => $id,
            'type' => 'essay',
            'difficulty' => $difficulty,
            'topic' => 'Explanation',
            'max_marks' => 6,
            'stem' => ['text' => "Essay $id"],
            'rubrics' => [['criterion' => 'Quality', 'max_marks' => 6, 'descriptors' => ['high' => 'Clear']]],
        ];
    }

    private function structured(string $id, string $difficulty): array
    {
        return [
            'external_id' => $id,
            'type' => 'structured',
            'difficulty' => $difficulty,
            'topic' => 'Explanation',
            'max_marks' => 4,
            'stem' => ['text' => "Structured $id"],
            'rubrics' => [['criterion' => 'Method', 'max_marks' => 4, 'descriptors' => ['high' => 'Complete']]],
        ];
    }
}
