<?php

namespace App\Services;

use App\Models\ExamPackage;
use App\Models\ExamPaper;
use App\Models\QuestionBank;
use App\Models\QuestionBankItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuestionBankService
{
    public function __construct(private readonly ExamPackageImportService $packageImporter) {}

    public function createBank(array $data, ?User $user = null): QuestionBank
    {
        return QuestionBank::query()->create([
            'code' => $data['code'],
            'title' => $data['title'],
            'subject' => $data['subject'] ?? null,
            'level' => $data['level'] ?? null,
            'status' => $data['status'] ?? 'active',
            'created_by' => $user?->id,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function updateBank(QuestionBank $bank, array $data): QuestionBank
    {
        $bank->forceFill([
            'code' => $data['code'],
            'title' => $data['title'],
            'subject' => $data['subject'] ?? null,
            'level' => $data['level'] ?? null,
            'status' => $data['status'] ?? $bank->status,
            'metadata' => $data['metadata'] ?? $bank->metadata,
        ])->save();

        return $bank->refresh();
    }

    public function upsertItem(QuestionBank $bank, array $payload): QuestionBankItem
    {
        return DB::transaction(function () use ($bank, $payload): QuestionBankItem {
            $this->validateItemShape($payload);

            $item = QuestionBankItem::query()->updateOrCreate(
                [
                    'question_bank_id' => $bank->id,
                    'external_id' => $payload['external_id'],
                ],
                $this->itemAttributes($payload, $bank),
            );

            $this->replaceChildren($item, $payload);

            return $item->load('options', 'rubrics');
        });
    }

    public function updateItem(QuestionBankItem $item, array $payload): QuestionBankItem
    {
        return DB::transaction(function () use ($item, $payload): QuestionBankItem {
            $this->validateItemShape($payload);

            $duplicate = QuestionBankItem::query()
                ->where('question_bank_id', $item->question_bank_id)
                ->where('external_id', $payload['external_id'])
                ->whereKeyNot($item->id)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['external_id' => 'Question external_id already exists in this bank.']);
            }

            $item->forceFill($this->itemAttributes($payload, $item->bank))->save();
            $this->replaceChildren($item, $payload);

            return $item->refresh()->load('options', 'rubrics');
        });
    }

    public function deleteItem(QuestionBankItem $item): void
    {
        $item->delete();
    }

    public function import(QuestionBank $bank, array $payload): array
    {
        return DB::transaction(function () use ($bank, $payload): array {
            if (($payload['mode'] ?? 'upsert') === 'replace') {
                $bank->items()->delete();
            }

            $created = 0;
            $updated = 0;

            foreach ($payload['questions'] as $index => $question) {
                $question['position'] ??= $index + 1;
                $item = $this->upsertItem($bank, $question);
                $item->wasRecentlyCreated ? $created++ : $updated++;
            }

            return [
                'created' => $created,
                'updated' => $updated,
                'total_items' => $bank->items()->count(),
            ];
        });
    }

    public function buildPackage(QuestionBank $bank, ExamPaper $paper, array $data, ?User $user = null): ExamPackage
    {
        $items = $this->selectItems($bank, $data);
        $shuffleQuestions = (bool) ($data['shuffle_questions'] ?? true);

        if ($shuffleQuestions) {
            $items = $items->shuffle()->values();
        }

        $questions = $items->values()->map(fn (QuestionBankItem $item, int $index) => $this->toPackageQuestion(
            $item,
            $index + 1,
            (bool) ($data['shuffle_options'] ?? true),
        ))->all();

        return $this->packageImporter->import($paper, [
            'version' => $data['version'] ?? null,
            'title' => $data['title'] ?? $paper->title,
            'duration_minutes' => $data['duration_minutes'] ?? $paper->duration_minutes,
            'strict_mode' => $data['strict_mode'] ?? true,
            'total_marks' => collect($questions)->sum('max_marks'),
            'metadata' => array_merge($data['metadata'] ?? [], [
                'source' => 'question_bank',
                'question_bank_id' => $bank->id,
                'question_bank_code' => $bank->code,
                'shuffle_questions' => $shuffleQuestions,
                'shuffle_options' => (bool) ($data['shuffle_options'] ?? true),
            ]),
            'questions' => $questions,
        ], $user);
    }

    private function itemAttributes(array $payload, QuestionBank $bank): array
    {
        return [
            'question_bank_id' => $bank->id,
            'external_id' => $payload['external_id'],
            'type' => $payload['type'],
            'difficulty' => $payload['difficulty'] ?? 'medium',
            'position' => (int) ($payload['position'] ?? ($bank->items()->max('position') + 1 ?: 1)),
            'topic' => $payload['topic'] ?? null,
            'max_marks' => (float) ($payload['max_marks'] ?? 1),
            'stem' => $payload['stem'],
            'correct_answer' => $payload['correct_answer'] ?? $this->correctAnswerFromOptions($payload),
            'validation_rules' => $this->validationRules($payload),
            'feedback' => $payload['feedback'] ?? null,
            'media' => $payload['media'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ];
    }

    private function replaceChildren(QuestionBankItem $item, array $payload): void
    {
        $item->options()->delete();
        $item->rubrics()->delete();

        foreach (array_values($payload['options'] ?? []) as $index => $option) {
            $item->options()->create([
                'external_id' => $option['external_id'],
                'position' => (int) ($option['position'] ?? $index + 1),
                'content' => $option['content'],
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'marks' => (float) ($option['marks'] ?? (($option['is_correct'] ?? false) ? $item->max_marks : 0)),
                'media' => $option['media'] ?? null,
                'metadata' => $option['metadata'] ?? null,
            ]);
        }

        foreach (array_values($payload['rubrics'] ?? []) as $rubric) {
            $item->rubrics()->create([
                'criterion' => $rubric['criterion'] ?? 'General',
                'max_marks' => (float) ($rubric['max_marks'] ?? $item->max_marks),
                'descriptors' => $rubric['descriptors'] ?? null,
            ]);
        }
    }

    private function validateItemShape(array $payload): void
    {
        $optionTypes = ['objective', 'checkbox'];
        $options = collect($payload['options'] ?? []);

        if (in_array($payload['type'], $optionTypes, true)) {
            if ($options->isEmpty()) {
                throw ValidationException::withMessages(['options' => 'Objective and checkbox questions require answer options.']);
            }

            if ($options->pluck('external_id')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['options' => 'Option external_id values must be unique per question.']);
            }

            if (! $options->contains(fn (array $option) => (bool) ($option['is_correct'] ?? false))) {
                throw ValidationException::withMessages(['options' => 'At least one option must be marked correct.']);
            }
        }

        if (! in_array($payload['type'], $optionTypes, true) && $options->isNotEmpty()) {
            throw ValidationException::withMessages(['options' => 'Only objective and checkbox questions may have options.']);
        }

        if ($payload['type'] === 'numerical') {
            $expected = data_get($payload, 'correct_answer.value', $payload['correct_answer'] ?? null);

            if ($expected !== null && ! is_numeric($expected)) {
                throw ValidationException::withMessages(['correct_answer' => 'Numerical correct_answer must be numeric.']);
            }
        }
    }

    private function validationRules(array $payload): array
    {
        $rules = $payload['validation_rules'] ?? [];

        if (in_array($payload['type'], ['essay', 'structured'], true)) {
            $rules['max_length'] ??= $payload['type'] === 'essay' ? 8000 : 12000;
        }

        return $rules;
    }

    private function correctAnswerFromOptions(array $payload): ?array
    {
        if (! in_array($payload['type'], ['objective', 'checkbox'], true)) {
            return null;
        }

        $correct = collect($payload['options'] ?? [])
            ->filter(fn (array $option) => (bool) ($option['is_correct'] ?? false))
            ->pluck('external_id')
            ->values()
            ->all();

        return ['option_ids' => $payload['type'] === 'objective' ? ($correct[0] ?? null) : $correct];
    }

    private function selectItems(QuestionBank $bank, array $data): Collection
    {
        $mix = collect($data['difficulty_mix'] ?? [])
            ->only(['easy', 'medium', 'hard'])
            ->map(fn ($count) => max(0, (int) $count))
            ->filter();
        $targetCount = (int) ($data['question_count'] ?? ($mix->sum() ?: 10));
        $selected = collect();
        $selectedIds = [];

        foreach ($mix as $difficulty => $count) {
            $items = $this->baseSelectionQuery($bank, $data)
                ->where('difficulty', $difficulty)
                ->whereNotIn('id', $selectedIds)
                ->inRandomOrder()
                ->limit($count)
                ->get();

            $selected = $selected->merge($items);
            $selectedIds = $selected->pluck('id')->all();
        }

        $remaining = $targetCount - $selected->count();

        if ($remaining > 0) {
            $selected = $selected->merge(
                $this->baseSelectionQuery($bank, $data)
                    ->whereNotIn('id', $selectedIds)
                    ->inRandomOrder()
                    ->limit($remaining)
                    ->get(),
            );
        }

        if ($selected->count() < $targetCount) {
            throw ValidationException::withMessages([
                'question_count' => "Question bank only has {$selected->count()} matching questions, but $targetCount are required.",
            ]);
        }

        $ids = $selected->take($targetCount)->pluck('id')->all();
        $itemsById = QuestionBankItem::query()
            ->with('options', 'rubrics')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return collect($ids)->map(fn (int $id) => $itemsById->get($id))->filter()->values();
    }

    private function baseSelectionQuery(QuestionBank $bank, array $data)
    {
        return $bank->items()
            ->when($data['topics'] ?? null, fn ($query, array $topics) => $query->whereIn('topic', $topics));
    }

    private function toPackageQuestion(QuestionBankItem $item, int $position, bool $shuffleOptions): array
    {
        $options = $item->options->values();

        if ($shuffleOptions) {
            $options = $options->shuffle()->values();
        }

        return [
            'external_id' => $item->external_id,
            'type' => $item->type,
            'position' => $position,
            'topic' => $item->topic,
            'max_marks' => (float) $item->max_marks,
            'stem' => $item->stem,
            'correct_answer' => $item->correct_answer,
            'validation_rules' => $item->validation_rules ?? [],
            'feedback' => $item->feedback,
            'metadata' => array_merge($item->metadata ?? [], [
                'source_question_bank_id' => $item->question_bank_id,
                'source_question_bank_item_id' => $item->id,
                'difficulty' => $item->difficulty,
                'media' => $item->media,
            ]),
            'options' => $options->map(fn ($option, int $index): array => [
                'external_id' => $option->external_id,
                'position' => $index + 1,
                'content' => $option->content,
                'is_correct' => $option->is_correct,
                'marks' => (float) $option->marks,
                'metadata' => array_merge($option->metadata ?? [], ['media' => $option->media]),
            ])->all(),
            'rubrics' => $item->rubrics->map(fn ($rubric): array => [
                'criterion' => $rubric->criterion,
                'max_marks' => (float) $rubric->max_marks,
                'descriptors' => $rubric->descriptors,
            ])->all(),
        ];
    }
}
