<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240'],
            'candidate_group_id' => ['nullable', 'integer', 'exists:candidate_groups,id'],
        ];
    }
}
