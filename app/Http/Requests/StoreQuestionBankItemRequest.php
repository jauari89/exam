<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionBankItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'external_id' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', Rule::in(['objective', 'checkbox', 'numerical', 'essay', 'structured'])],
            'difficulty' => ['nullable', 'string', Rule::in(['easy', 'medium', 'hard'])],
            'position' => ['nullable', 'integer', 'min:1'],
            'topic' => ['nullable', 'string', 'max:160'],
            'max_marks' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'stem' => ['required', 'array'],
            'stem.text' => ['nullable', 'string', 'max:20000'],
            'stem.image' => ['nullable', 'string', 'max:2048'],
            'stem.math' => ['nullable', 'string', 'max:2000'],
            'stem.table' => ['nullable', 'array'],
            'correct_answer' => ['nullable'],
            'validation_rules' => ['nullable', 'array'],
            'feedback' => ['nullable', 'array'],
            'media' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'options' => ['nullable', 'array'],
            'options.*.external_id' => ['required_with:options', 'string', 'max:80'],
            'options.*.position' => ['nullable', 'integer', 'min:1'],
            'options.*.content' => ['required_with:options', 'array'],
            'options.*.content.text' => ['nullable', 'string', 'max:12000'],
            'options.*.content.image' => ['nullable', 'string', 'max:2048'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.marks' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'options.*.media' => ['nullable', 'array'],
            'options.*.metadata' => ['nullable', 'array'],
            'rubrics' => ['nullable', 'array'],
            'rubrics.*.criterion' => ['required_with:rubrics', 'string', 'max:255'],
            'rubrics.*.max_marks' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'rubrics.*.descriptors' => ['nullable', 'array'],
        ];
    }
}
