<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ManualLeadIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(collect($this->all())
            ->map(fn (mixed $value): mixed => is_string($value)
                ? preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', trim($value))
                : $value)
            ->all());
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'min:1', 'max:40'],
            'last_name' => ['required', 'string', 'min:1', 'max:80'],
            'phone' => ['required', 'string', 'min:7', 'max:30', 'regex:/^[+()0-9.\-\s]+$/'],
            'email' => ['required', 'email:rfc', 'max:100'],
            'customer_type' => ['required', Rule::in(['Individual', 'Business'])],
            'company' => ['nullable', 'string', 'max:200'],
            'zip' => ['required', 'string', 'regex:/^\d{5}(?:-\d{4})?$/'],
            'service_interest' => ['required', 'string', Rule::in(config('lead-intake.service_interests', []))],
            'selected_plan' => ['nullable', 'string', 'max:120'],
            'preferred_contact' => ['required', Rule::in(['Phone', 'Text', 'Email'])],
            'timeline' => ['nullable', 'string', 'max:80'],
            'details' => ['required', 'string', 'min:10', 'max:3000'],
            'contact_consent' => ['sometimes', 'accepted'],
            'sms_consent' => ['sometimes', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number.',
            'zip.regex' => 'Enter a valid ZIP code.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('customer_type') === 'Business' && blank($this->input('company'))) {
                    $validator->errors()->add('company', 'Company name is required for business leads.');
                }
            },
        ];
    }

    public function normalizedLeadData(): array
    {
        $validated = $this->validated();
        $recordedAt = now();
        $contactConsent = $this->boolean('contact_consent');
        $smsConsent = $this->boolean('sms_consent');

        return [
            'source' => 'manual',
            'status' => 'received',
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'customer_type' => $validated['customer_type'],
            'company' => $validated['customer_type'] === 'Business' ? ($validated['company'] ?? null) : null,
            'zip' => $validated['zip'],
            'service_interest' => $validated['service_interest'],
            'selected_plan' => $validated['selected_plan'] ?? null,
            'preferred_contact' => $validated['preferred_contact'],
            'timeline' => $validated['timeline'] ?? null,
            'details' => $validated['details'],
            'contact_consent_at' => $contactConsent ? $recordedAt : null,
            'contact_consent_ip' => null,
            'contact_consent_version' => $contactConsent ? 'manual-v1' : null,
            'sms_consent_at' => $smsConsent ? $recordedAt : null,
            'sms_consent_ip' => null,
            'sms_consent_version' => $smsConsent ? 'manual-v1' : null,
            'ip_address' => null,
            'user_agent' => null,
        ];
    }
}
