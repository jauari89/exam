<?php

namespace App\Services;

use App\Models\AttemptSnapshot;
use App\Models\ExamAttempt;
use App\Models\ExamPackage;
use App\Models\ExamSession;
use Illuminate\Validation\ValidationException;

class AttemptSnapshotService
{
    public function createForAttempt(ExamAttempt $attempt): AttemptSnapshot
    {
        $session = $attempt->session()->with('paper')->firstOrFail();
        $package = $this->selectPackage($session);
        $payload = $this->snapshotPayload($package, $session);

        return AttemptSnapshot::query()->create([
            'exam_attempt_id' => $attempt->id,
            'exam_package_id' => $package->id,
            'snapshot_version' => 1,
            'package_checksum' => $package->checksum,
            'duration_minutes' => $session->duration_minutes,
            'total_marks' => $payload['total_marks'],
            'payload' => $payload,
            'published_at' => now(),
        ]);
    }

    public function candidatePayload(AttemptSnapshot $snapshot, bool $revealFeedback = false): array
    {
        $payload = $snapshot->payload;
        $strict = (bool) data_get($payload, 'strict_mode', true);

        $payload['questions'] = collect($payload['questions'])->map(function (array $question) use ($strict, $revealFeedback): array {
            unset($question['correct_answer']);

            if ($strict || ! $revealFeedback) {
                unset($question['feedback']);
            }

            $question['options'] = collect($question['options'] ?? [])->map(function (array $option) use ($strict): array {
                if ($strict) {
                    unset($option['is_correct'], $option['marks']);
                }

                return $option;
            })->all();

            return $question;
        })->all();

        return $payload;
    }

    private function selectPackage(ExamSession $session): ExamPackage
    {
        $paper = $session->paper ?? $session->exam->papers()->latest('version')->first();

        if (! $paper) {
            throw ValidationException::withMessages(['exam_paper_id' => 'Exam session has no paper.']);
        }

        $publishedPackageId = data_get($session->settings, 'published_package_id');
        $package = $publishedPackageId
            ? $paper->packages()->with('questions.options', 'questions.rubrics')->whereKey($publishedPackageId)->first()
            : $paper->packages()->with('questions.options', 'questions.rubrics')->latest('version')->first();

        if (! $package) {
            throw ValidationException::withMessages(['exam_package_id' => 'Exam paper has no published package.']);
        }

        return $package;
    }

    private function snapshotPayload(ExamPackage $package, ExamSession $session): array
    {
        $questions = $package->questions->map(function ($question): array {
            return [
                'id' => $question->id,
                'external_id' => $question->external_id,
                'type' => $question->type,
                'position' => $question->position,
                'topic' => $question->topic,
                'max_marks' => (float) $question->max_marks,
                'stem' => $question->stem,
                'correct_answer' => $question->correct_answer,
                'validation_rules' => $question->validation_rules ?? [],
                'feedback' => $question->feedback,
                'options' => $question->options->map(fn ($option) => [
                    'id' => $option->id,
                    'external_id' => $option->external_id,
                    'position' => $option->position,
                    'content' => $option->content,
                    'is_correct' => $option->is_correct,
                    'marks' => (float) $option->marks,
                ])->all(),
                'rubrics' => $question->rubrics->map(fn ($rubric) => [
                    'criterion' => $rubric->criterion,
                    'max_marks' => (float) $rubric->max_marks,
                    'descriptors' => $rubric->descriptors,
                ])->all(),
            ];
        })->all();

        return [
            'exam' => [
                'id' => $session->exam_id,
                'session_id' => $session->id,
                'name' => $session->name,
                'mode' => $session->mode,
            ],
            'package_id' => $package->id,
            'package_checksum' => $package->checksum,
            'strict_mode' => $session->mode === 'strict' || $package->strict_mode,
            'duration_minutes' => $session->duration_minutes,
            'total_marks' => collect($questions)->sum('max_marks'),
            'questions' => $questions,
        ];
    }
}
