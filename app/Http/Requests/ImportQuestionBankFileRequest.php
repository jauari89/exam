<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportQuestionBankFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480'],
            'mode' => ['nullable', 'string', 'in:upsert,replace'],
        ];
    }
}
