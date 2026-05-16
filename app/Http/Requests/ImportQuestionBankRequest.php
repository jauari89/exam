<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportQuestionBankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['nullable', 'string', 'in:upsert,replace'],
            'questions' => ['required', 'array', 'min:1', 'max:500'],
            'questions.*.external_id' => ['required', 'string', 'max:100'],
            'questions.*.type' => ['required', 'string', 'in:objective,checkbox,numerical,essay,structured'],
            'questions.*.difficulty' => ['nullable', 'string', 'in:easy,medium,hard'],
            'questions.*.position' => ['nullable', 'integer', 'min:1'],
            'questions.*.topic' => ['nullable', 'string', 'max:160'],
            'questions.*.max_marks' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'questions.*.stem' => ['required', 'array'],
            'questions.*.correct_answer' => ['nullable'],
            'questions.*.validation_rules' => ['nullable', 'array'],
            'questions.*.feedback' => ['nullable', 'array'],
            'questions.*.media' => ['nullable', 'array'],
            'questions.*.metadata' => ['nullable', 'array'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.options.*.external_id' => ['required_with:questions.*.options', 'string', 'max:80'],
            'questions.*.options.*.content' => ['required_with:questions.*.options', 'array'],
            'questions.*.options.*.is_correct' => ['nullable', 'boolean'],
            'questions.*.options.*.marks' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'questions.*.options.*.media' => ['nullable', 'array'],
            'questions.*.options.*.metadata' => ['nullable', 'array'],
            'questions.*.rubrics' => ['nullable', 'array'],
        ];
    }
}
