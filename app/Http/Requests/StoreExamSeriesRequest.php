<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExamSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:draft,active,archived'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
