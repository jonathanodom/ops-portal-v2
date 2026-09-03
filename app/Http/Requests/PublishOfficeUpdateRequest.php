<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PublishOfficeUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
            'audience_type' => ['required', Rule::in(['all_staff', 'selected_staff'])],
            'recipient_user_ids' => ['exclude_unless:audience_type,selected_staff', 'required_if:audience_type,selected_staff', 'array', 'min:1'],
            'recipient_user_ids.*' => ['integer', 'distinct'],
            'publish_token' => ['required', 'string', 'min:8', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_user_ids.required_if' => 'Select at least one staff member.',
            'recipient_user_ids.min' => 'Select at least one staff member.',
        ];
    }
}
