<?php

namespace App\Services;

use App\Models\ExamPackage;
use App\Models\ExamPaper;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExamPackageImportService
{
    public function __construct(private readonly ExamPackageValidator $validator) {}

    public function import(ExamPaper $paper, array $payload, ?User $user = null): ExamPackage
    {
        $validated = $this->validator->validate($payload);
        $checksum = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($paper, $payload, $validated, $checksum, $user): ExamPackage {
            $version = (int) ($payload['version'] ?? ($paper->packages()->max('version') + 1 ?: 1));

            $package = ExamPackage::query()->create([
                'exam_paper_id' => $paper->id,
                'version' => $version,
                'checksum' => $checksum,
                'strict_mode' => (bool) ($payload['strict_mode'] ?? true),
                'imported_by' => $user?->id,
                'published_at' => now(),
                'source_payload' => $payload,
                'validated_payload' => $validated,
            ]);

            foreach ($validated['questions'] as $questionPayload) {
                $question = $package->questions()->create(collect($questionPayload)->except(['options', 'rubrics'])->all());

                foreach ($questionPayload['options'] as $optionPayload) {
                    $question->options()->create($optionPayload);
                }

                foreach ($questionPayload['rubrics'] as $rubric) {
                    $question->rubrics()->create([
                        'criterion' => $rubric['criterion'] ?? $rubric['criteria'] ?? 'General',
                        'max_marks' => (float) ($rubric['max_marks'] ?? $question->max_marks),
                        'descriptors' => $rubric['descriptors'] ?? null,
                    ]);
                }
            }

            $paper->forceFill([
                'total_marks' => $validated['total_marks'],
                'duration_minutes' => $validated['duration_minutes'],
                'status' => 'published',
            ])->save();

            return $package->load('questions.options', 'questions.rubrics');
        });
    }
}
