<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ExamPackageValidator
{
    private const TYPES = ['objective', 'checkbox', 'numerical', 'essay', 'structured'];

    public function validate(array $payload): array
    {
        if (! isset($payload['questions']) || ! is_array($payload['questions']) || $payload['questions'] === []) {
            throw ValidationException::withMessages(['questions' => 'Package must contain at least one question.']);
        }

        $externalIds = [];
        $total = 0.0;
        $questions = [];

        foreach (array_values($payload['questions']) as $index => $question) {
            $type = $question['type'] ?? null;
            $externalId = (string) ($question['external_id'] ?? 'q'.($index + 1));

            if (! in_array($type, self::TYPES, true)) {
                throw ValidationException::withMessages(["questions.$index.type" => 'Unsupported question type.']);
            }

            if (in_array($externalId, $externalIds, true)) {
                throw ValidationException::withMessages(["questions.$index.external_id" => 'Duplicate question external_id.']);
            }

            $externalIds[] = $externalId;
            $maxMarks = max(0.0, (float) ($question['max_marks'] ?? 1));
            $total += $maxMarks;

            $options = collect($question['options'] ?? [])->values()->map(function (array $option, int $optionIndex): array {
                return [
                    'external_id' => (string) ($option['external_id'] ?? 'o'.($optionIndex + 1)),
                    'position' => $optionIndex + 1,
                    'content' => $option['content'] ?? ['text' => (string) ($option['text'] ?? '')],
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                    'marks' => (float) ($option['marks'] ?? (($option['is_correct'] ?? false) ? 1 : 0)),
                    'metadata' => $option['metadata'] ?? null,
                ];
            })->all();

            if (in_array($type, ['objective', 'checkbox'], true) && $options === []) {
                throw ValidationException::withMessages(["questions.$index.options" => 'Objective and checkbox questions require options.']);
            }

            $questions[] = [
                'external_id' => $externalId,
                'type' => $type,
                'position' => (int) ($question['position'] ?? $index + 1),
                'topic' => $question['topic'] ?? null,
                'max_marks' => $maxMarks,
                'stem' => $question['stem'] ?? ['text' => (string) ($question['text'] ?? '')],
                'correct_answer' => $question['correct_answer'] ?? null,
                'validation_rules' => $question['validation_rules'] ?? [],
                'feedback' => $question['feedback'] ?? null,
                'metadata' => $question['metadata'] ?? null,
                'options' => $options,
                'rubrics' => $question['rubrics'] ?? [],
            ];
        }

        return [
            'title' => $payload['title'] ?? null,
            'duration_minutes' => (int) ($payload['duration_minutes'] ?? 90),
            'total_marks' => (float) ($payload['total_marks'] ?? $total),
            'questions' => $questions,
            'metadata' => $payload['metadata'] ?? [],
        ];
    }
}
