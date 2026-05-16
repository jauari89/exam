<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ModerationReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['nullable', 'string', 'in:accepted,adjusted,returned'],
            'final_score' => ['nullable', 'numeric', 'min:0'],
            'comments' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
