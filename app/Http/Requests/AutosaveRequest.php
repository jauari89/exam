<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AutosaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_sequence' => ['required', 'integer', 'min:1'],
            'answers' => ['present', 'array'],
            'context' => ['nullable', 'array'],
        ];
    }
}
