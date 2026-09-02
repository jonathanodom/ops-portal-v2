<?php

namespace App\Http\Requests;

use App\Models\CommercialLeadIntake;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeadFollowUpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'engagement_status' => ['required', Rule::in(CommercialLeadIntake::ENGAGEMENT_STATUSES)],
            'next_follow_up_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'next_follow_up_at.date_format' => 'Enter a valid follow-up date and time.',
        ];
    }

    public function nextFollowUpAt(): ?CarbonImmutable
    {
        $value = $this->validated('next_follow_up_at');

        return filled($value)
            ? CarbonImmutable::createFromFormat(
                'Y-m-d\TH:i',
                $value,
                $this->attributes->get('organization')->timezone,
            )->utc()
            : null;
    }
}
