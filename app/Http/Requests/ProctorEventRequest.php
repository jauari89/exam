<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProctorEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_attempt_id' => ['nullable', 'integer', 'exists:exam_attempts,id'],
            'event_type' => ['required', 'string', 'max:100'],
            'severity' => ['nullable', 'string', 'in:info,warning,critical'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
