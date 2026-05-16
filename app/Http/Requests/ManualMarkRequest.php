<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ManualMarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'earned_marks' => ['required', 'numeric', 'min:0'],
            'marker_role' => ['nullable', 'string', 'in:first_marker,second_marker,reviewer'],
            'comments' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'string', 'in:pending_review,accepted,returned'],
        ];
    }
}
