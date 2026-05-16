<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarkingAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_session_id' => ['required', 'integer', 'exists:exam_sessions,id'],
            'marker_id' => ['required', 'integer', 'exists:users,id'],
            'reviewer_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
        ];
    }
}
