<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BuildPackageFromBankRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:255'],
            'question_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'difficulty_mix' => ['nullable', 'array'],
            'difficulty_mix.easy' => ['nullable', 'integer', 'min:0', 'max:200'],
            'difficulty_mix.medium' => ['nullable', 'integer', 'min:0', 'max:200'],
            'difficulty_mix.hard' => ['nullable', 'integer', 'min:0', 'max:200'],
            'topics' => ['nullable', 'array'],
            'topics.*' => ['string', 'max:160'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'shuffle_options' => ['nullable', 'boolean'],
            'strict_mode' => ['nullable', 'boolean'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
