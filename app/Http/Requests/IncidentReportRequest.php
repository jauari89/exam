<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IncidentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_session_id' => ['required', 'integer', 'exists:exam_sessions,id'],
            'exam_attempt_id' => ['nullable', 'integer', 'exists:exam_attempts,id'],
            'candidate_id' => ['nullable', 'integer', 'exists:candidates,id'],
            'severity' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'evidence' => ['nullable', 'array'],
        ];
    }
}
