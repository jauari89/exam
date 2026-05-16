<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExamSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_id' => ['required', 'integer', 'exists:exams,id'],
            'exam_paper_id' => ['nullable', 'integer', 'exists:exam_papers,id'],
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'mode' => ['required', 'string', 'in:strict,tryout'],
            'status' => ['nullable', 'string', 'in:scheduled,active,closed,cancelled'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'settings' => ['nullable', 'array'],
        ];
    }
}
