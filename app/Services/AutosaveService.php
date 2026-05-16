<?php

namespace App\Services;

use App\Models\AttemptSnapshot;
use App\Models\Autosave;
use App\Models\ExamAttempt;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AutosaveService
{
    public function save(ExamAttempt $attempt, array $payload, Request $request): Autosave
    {
        $snapshot = $attempt->snapshot ?: $attempt->snapshot()->firstOrFail();
        $normalized = $this->normalize($snapshot, $payload);

        $autosave = Autosave::query()->updateOrCreate(
            [
                'exam_attempt_id' => $attempt->id,
                'client_sequence' => (int) ($payload['client_sequence'] ?? 1),
            ],
            [
                'payload' => $payload,
                'normalized_answers' => $normalized,
                'validation_errors' => null,
                'saved_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );

        $attempt->forceFill(['last_seen_at' => now(), 'status' => 'in_progress'])->save();

        return $autosave;
    }

    public function normalize(AttemptSnapshot $snapshot, array $payload): array
    {
        $questions = collect($snapshot->payload['questions'] ?? [])->keyBy(fn ($question) => (string) $question['id']);
        $rawAnswers = $this->answerMap($payload['answers'] ?? []);
        $normalized = [];

        foreach ($rawAnswers as $questionId => $answerPayload) {
            $questionId = (string) $questionId;

            if (! $questions->has($questionId)) {
                throw ValidationException::withMessages(['answers' => "Unknown question id [$questionId]."]);
            }

            $question = $questions[$questionId];
            $normalized[$questionId] = $this->normalizeAnswer($question, $answerPayload);
        }

        return $normalized;
    }

    private function answerMap(array $answers): array
    {
        if (array_is_list($answers)) {
            return collect($answers)->mapWithKeys(function (array $answer): array {
                return [(string) $answer['question_id'] => $answer['answer'] ?? $answer['value'] ?? null];
            })->all();
        }

        return $answers;
    }

    private function normalizeAnswer(array $question, mixed $answerPayload): array
    {
        $type = $question['type'];
        $optionIds = collect($question['options'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();

        $answer = match ($type) {
            'objective' => $this->normalizeObjective($answerPayload, $optionIds),
            'checkbox' => $this->normalizeCheckbox($answerPayload, $optionIds),
            'numerical' => $this->normalizeNumerical($answerPayload),
            'essay', 'structured' => $this->normalizeText($answerPayload, (int) data_get($question, 'validation_rules.max_length', $type === 'essay' ? 8000 : 12000)),
            default => throw ValidationException::withMessages(['answers' => "Unsupported question type [$type]."]),
        };

        return [
            'question_id' => (int) $question['id'],
            'question_external_id' => $question['external_id'],
            'type' => $type,
            'answer' => $answer,
        ];
    }

    private function normalizeObjective(mixed $payload, array $optionIds): ?int
    {
        $optionId = is_array($payload) ? ($payload['option_id'] ?? $payload['value'] ?? null) : $payload;

        if ($optionId === null || $optionId === '') {
            return null;
        }

        $optionId = (int) $optionId;

        if (! in_array($optionId, $optionIds, true)) {
            throw ValidationException::withMessages(['answers' => "Unknown option id [$optionId]."]);
        }

        return $optionId;
    }

    private function normalizeCheckbox(mixed $payload, array $optionIds): array
    {
        $values = is_array($payload) && array_key_exists('option_ids', $payload) ? $payload['option_ids'] : $payload;
        $values = is_array($values) ? $values : [];
        $deduped = collect($values)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        foreach ($deduped as $optionId) {
            if (! in_array($optionId, $optionIds, true)) {
                throw ValidationException::withMessages(['answers' => "Unknown option id [$optionId]."]);
            }
        }

        return $deduped;
    }

    private function normalizeNumerical(mixed $payload): ?float
    {
        $value = is_array($payload) ? ($payload['value'] ?? null) : $payload;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages(['answers' => 'Numerical answers must be numeric.']);
        }

        return (float) $value;
    }

    private function normalizeText(mixed $payload, int $maxLength): string
    {
        $value = is_array($payload) ? ($payload['text'] ?? $payload['value'] ?? '') : (string) $payload;
        $value = trim((string) $value);

        if (mb_strlen($value) > $maxLength) {
            throw ValidationException::withMessages(['answers' => "Answer exceeds the $maxLength character limit."]);
        }

        return $value;
    }
}
