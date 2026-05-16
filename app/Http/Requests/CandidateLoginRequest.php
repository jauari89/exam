<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CandidateLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_session_id' => ['required', 'integer', 'exists:exam_sessions,id'],
            'name' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'min:8', 'max:80'],
        ];
    }
}
