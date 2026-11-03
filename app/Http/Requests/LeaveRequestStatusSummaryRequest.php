<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LeaveRequestStatusSummaryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'show_all' => ['nullable', 'boolean'],
            'month_year' => ['nullable', 'date_format:m-Y'],
            // 'user_uuid' => ['nullable', 'string', 'uuid'],
        ];
    }
}
