<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'candidate_group_id' => ['nullable', 'integer', 'exists:candidate_groups,id'],
            'candidate_number' => ['required_without:candidates', 'string', 'max:80'],
            'name' => ['required_without:candidates', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'external_id' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
            'candidates' => ['nullable', 'array'],
            'candidates.*.candidate_group_id' => ['nullable', 'integer', 'exists:candidate_groups,id'],
            'candidates.*.candidate_number' => ['required_with:candidates', 'string', 'max:80'],
            'candidates.*.name' => ['required_with:candidates', 'string', 'max:255'],
            'candidates.*.email' => ['nullable', 'email', 'max:255'],
            'candidates.*.external_id' => ['nullable', 'string', 'max:120'],
            'candidates.*.metadata' => ['nullable', 'array'],
        ];
    }
}
