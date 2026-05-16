<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PublishExamPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_session_id' => ['required', 'integer', 'exists:exam_sessions,id'],
            'status' => ['nullable', 'string', 'in:scheduled,active,closed'],
        ];
    }
}
