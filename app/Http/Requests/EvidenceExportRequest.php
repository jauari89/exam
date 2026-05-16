<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EvidenceExportRequest extends FormRequest
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
            'format' => ['nullable', 'string', 'in:json,zip'],
        ];
    }
}
