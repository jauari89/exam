<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_session_id' => ['required', 'integer', 'exists:exam_sessions,id'],
            'candidate_ids' => ['required_without_all:candidate_group_id,all_candidates', 'array', 'min:1'],
            'candidate_ids.*' => ['integer', 'exists:candidates,id'],
            'candidate_group_id' => ['nullable', 'integer', 'exists:candidate_groups,id'],
            'all_candidates' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
