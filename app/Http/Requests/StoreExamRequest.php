<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_series_id' => ['required', 'integer', 'exists:exam_series,id'],
            'code' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:60'],
            'mode' => ['nullable', 'string', 'in:strict,tryout'],
            'status' => ['nullable', 'string', 'in:draft,published,archived'],
            'default_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'randomize_questions' => ['nullable', 'boolean'],
            'reveal_feedback' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'paper' => ['nullable', 'array'],
            'paper.code' => ['required_with:paper', 'string', 'max:60'],
            'paper.title' => ['required_with:paper', 'string', 'max:255'],
            'paper.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'session' => ['nullable', 'array'],
            'session.name' => ['required_with:session', 'string', 'max:255'],
            'session.starts_at' => ['required_with:session', 'date'],
            'session.ends_at' => ['required_with:session', 'date', 'after:session.starts_at'],
            'session.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
        ];
    }
}
