<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\CandidateExamToken;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamPaper;
use App\Models\ExamSeries;
use App\Models\ExamSession;
use App\Services\CandidateTokenService;
use App\Services\ExamPackageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidateSecurityFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_token_is_hashed_and_one_time(): void
    {
        $fixture = $this->examFixture();
        $issued = app(CandidateTokenService::class)->issue($fixture['candidate'], $fixture['session']);

        $this->assertDatabaseHas('candidate_exam_tokens', [
            'id' => $issued['token']->id,
            'token_suffix' => substr(str_replace('-', '', $issued['plain_token']), -4),
        ]);
        $this->assertStringNotContainsString(str_replace('-', '', $issued['plain_token']), CandidateExamToken::first()->token_hash);

        $response = $this->postJson('/api/candidate/login', [
            'exam_session_id' => $fixture['session']->id,
            'name' => 'Ada Lovelace',
            'token' => $issued['plain_token'],
        ])->assertOk();

        $response->assertJsonStructure(['attempt_id', 'session_key', 'expires_at', 'server_time']);

        $this->postJson('/api/candidate/login', [
            'exam_session_id' => $fixture['session']->id,
            'name' => 'Ada Lovelace',
            'token' => $issued['plain_token'],
        ])->assertStatus(422);
    }

    public function test_candidate_can_login_with_candidate_number_and_matching_token(): void
    {
        $fixture = $this->examFixture();
        $issued = app(CandidateTokenService::class)->issue($fixture['candidate'], $fixture['session']);

        $this->postJson('/api/candidate/login', [
            'exam_session_id' => $fixture['session']->id,
            'name' => 'C-001',
            'token' => $issued['plain_token'],
        ])->assertOk()
            ->assertJsonStructure(['attempt_id', 'session_key', 'expires_at', 'server_time']);
    }

    public function test_attempt_rejects_unknown_question_and_option_then_deduplicates_checkbox_submit_idempotently(): void
    {
        $fixture = $this->examFixture();
        $issued = app(CandidateTokenService::class)->issue($fixture['candidate'], $fixture['session']);

        $login = $this->postJson('/api/candidate/login', [
            'exam_session_id' => $fixture['session']->id,
            'name' => 'Ada Lovelace',
            'token' => $issued['plain_token'],
        ])->json();

        $attemptId = $login['attempt_id'];
        $headers = ['X-Candidate-Session' => $login['session_key']];
        $attempt = ExamAttempt::with('snapshot')->findOrFail($attemptId);
        $questions = collect($attempt->snapshot->payload['questions']);
        $objective = $questions->firstWhere('external_id', 'Q1');
        $checkbox = $questions->firstWhere('external_id', 'Q2');

        $this->postJson("/api/candidate/attempts/$attemptId/autosave", [
            'client_sequence' => 1,
            'answers' => ['99999' => 1],
        ], $headers)->assertStatus(422);

        $this->postJson("/api/candidate/attempts/$attemptId/autosave", [
            'client_sequence' => 2,
            'answers' => [(string) $objective['id'] => 99999],
        ], $headers)->assertStatus(422);

        $payload = [
            'answers' => [
                (string) $objective['id'] => $objective['options'][0]['id'],
                (string) $checkbox['id'] => [
                    $checkbox['options'][0]['id'],
                    $checkbox['options'][0]['id'],
                    $checkbox['options'][1]['id'],
                    $checkbox['options'][2]['id'],
                ],
            ],
        ];

        $this->postJson("/api/candidate/attempts/$attemptId/autosave", ['client_sequence' => 3] + $payload, $headers)->assertOk();

        $first = $this->withHeaders($headers + ['Idempotency-Key' => 'submit-1'])
            ->postJson("/api/candidate/attempts/$attemptId/submit", $payload + ['idempotency_key' => 'submit-1'])
            ->assertOk()
            ->json('submission');

        $second = $this->withHeaders($headers + ['Idempotency-Key' => 'submit-1'])
            ->postJson("/api/candidate/attempts/$attemptId/submit", $payload + ['idempotency_key' => 'submit-1'])
            ->assertOk()
            ->json('submission');

        $this->assertSame($first['id'], $second['id']);
        $this->assertDatabaseHas('scores', [
            'submission_id' => $first['id'],
            'total_score' => 3,
            'max_score' => 3,
        ]);
    }

    private function examFixture(): array
    {
        $series = ExamSeries::query()->create(['code' => 'SER-1', 'title' => 'Series']);
        $exam = Exam::query()->create([
            'exam_series_id' => $series->id,
            'code' => 'MATH',
            'title' => 'Math',
            'mode' => 'strict',
            'default_duration_minutes' => 60,
        ]);
        $paper = ExamPaper::query()->create([
            'exam_id' => $exam->id,
            'code' => 'P1',
            'title' => 'Paper 1',
            'duration_minutes' => 60,
        ]);
        $session = ExamSession::query()->create([
            'exam_id' => $exam->id,
            'exam_paper_id' => $paper->id,
            'name' => 'Morning',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'duration_minutes' => 60,
            'mode' => 'strict',
        ]);

        app(ExamPackageImportService::class)->import($paper, [
            'version' => 1,
            'questions' => [
                [
                    'external_id' => 'Q1',
                    'type' => 'objective',
                    'max_marks' => 1,
                    'stem' => ['text' => '2 + 2 = ?'],
                    'options' => [
                        ['external_id' => 'A', 'content' => ['text' => '4'], 'is_correct' => true, 'marks' => 1],
                        ['external_id' => 'B', 'content' => ['text' => '5'], 'is_correct' => false, 'marks' => 0],
                    ],
                ],
                [
                    'external_id' => 'Q2',
                    'type' => 'checkbox',
                    'max_marks' => 2,
                    'stem' => ['text' => 'Select primes.'],
                    'options' => [
                        ['external_id' => 'A', 'content' => ['text' => '2'], 'is_correct' => true, 'marks' => 1],
                        ['external_id' => 'B', 'content' => ['text' => '3'], 'is_correct' => true, 'marks' => 1],
                        ['external_id' => 'C', 'content' => ['text' => '4'], 'is_correct' => false, 'marks' => 0],
                    ],
                ],
            ],
        ]);

        $candidate = Candidate::query()->create([
            'candidate_number' => 'C-001',
            'name' => 'Ada Lovelace',
            'normalized_name' => Candidate::normalizeName('Ada Lovelace'),
        ]);

        return compact('series', 'exam', 'paper', 'session', 'candidate');
    }
}
