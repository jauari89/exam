<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportExamPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_paper_id' => ['required', 'integer', 'exists:exam_papers,id'],
            'version' => ['nullable', 'integer', 'min:1'],
            'strict_mode' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'total_marks' => ['nullable', 'numeric', 'min:0'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.external_id' => ['nullable', 'string', 'max:100'],
            'questions.*.type' => ['required', 'string', 'in:objective,checkbox,numerical,essay,structured'],
            'questions.*.max_marks' => ['nullable', 'numeric', 'min:0'],
            'questions.*.stem' => ['nullable', 'array'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.correct_answer' => ['nullable'],
            'questions.*.validation_rules' => ['nullable', 'array'],
            'questions.*.rubrics' => ['nullable', 'array'],
        ];
    }
}
