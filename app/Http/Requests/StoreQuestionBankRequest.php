<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuestionBankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $bankId = $this->route('questionBank')?->getKey();

        return [
            'code' => ['required', 'string', 'max:80', 'unique:question_banks,code'.($bankId ? ','.$bankId : '')],
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:120'],
            'level' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:active,draft,archived'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
