<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PublicLeadIntakeRequest extends FormRequest
{
    private const CUSTOMER_TYPES = [
        'Individual',
        'Business',
        'Residential',
        'Commercial',
        'Church / nonprofit',
        'Church / Non-Profit',
        'Builder / contractor',
    ];

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
            'firstName' => ['required', 'string', 'min:1', 'max:40'],
            'lastName' => ['required', 'string', 'min:1', 'max:80'],
            'phone' => ['required', 'string', 'min:7', 'max:30', 'regex:/^[+()0-9.\-\s]+$/'],
            'email' => ['required', 'email:rfc', 'max:100'],
            'customerType' => ['required', 'string', Rule::in(self::CUSTOMER_TYPES)],
            'zip' => ['required', 'string', 'regex:/^\d{5}(?:-\d{4})?$/'],
            'company' => ['nullable', 'string', 'max:200'],
            'serviceInterest' => ['required', 'string', Rule::in(config('lead-intake.service_interests', []))],
            'selectedPlan' => ['nullable', 'string', 'max:120'],
            'preferredContact' => ['required', 'string', Rule::in(['Phone', 'Text', 'Email'])],
            'timeline' => ['nullable', 'string', 'max:80'],
            'details' => ['required', 'string', 'min:10', 'max:3000'],
            'originatingPage' => ['nullable', 'string', 'max:255'],
            'utmSource' => ['nullable', 'string', 'max:255'],
            'utmMedium' => ['nullable', 'string', 'max:255'],
            'utmCampaign' => ['nullable', 'string', 'max:255'],
            'utmTerm' => ['nullable', 'string', 'max:255'],
            'utmContent' => ['nullable', 'string', 'max:255'],
            'referrer' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'prohibited'],
            'consent' => ['required', 'accepted'],
            'smsConsent' => ['sometimes', 'boolean'],
            'turnstileToken' => ['nullable', 'string', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'website.prohibited' => 'We could not submit your request.',
            'phone.regex' => 'Enter a valid phone number.',
            'zip.regex' => 'Enter a valid ZIP code.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->isEmpty()) {
                    return;
                }

                if ($this->isBusinessType((string) $this->input('customerType')) && blank($this->input('company'))) {
                    $validator->errors()->add('company', 'Company name is required for business leads.');
                }

                $secret = (string) config('lead-intake.turnstile_secret');
                if ($secret === '') {
                    return;
                }

                $token = (string) $this->input('turnstileToken', '');
                if ($token === '') {
                    $validator->errors()->add('turnstileToken', 'Please complete the verification check.');

                    return;
                }

                try {
                    $response = Http::asForm()
                        ->timeout(5)
                        ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                            'secret' => $secret,
                            'response' => $token,
                            'remoteip' => $this->ip(),
                        ]);
                } catch (ConnectionException) {
                    $validator->errors()->add('turnstileToken', 'Please complete the verification check.');

                    return;
                }

                if (! $response->ok() || $response->json('success') !== true) {
                    $validator->errors()->add('turnstileToken', 'Please complete the verification check.');
                }
            },
        ];
    }

    public function normalizedLeadData(): array
    {
        $validated = $this->validated();
        $customerType = $this->normalizedCustomerType($validated['customerType']);

        return [
            'first_name' => $validated['firstName'],
            'last_name' => $validated['lastName'],
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'customer_type' => $customerType,
            'zip' => $validated['zip'],
            'company' => $customerType === 'Business' ? ($validated['company'] ?? null) : null,
            'service_interest' => $validated['serviceInterest'],
            'selected_plan' => $validated['selectedPlan'] ?? null,
            'preferred_contact' => $validated['preferredContact'],
            'timeline' => $validated['timeline'] ?? null,
            'details' => $validated['details'],
            'originating_page' => $validated['originatingPage'] ?? null,
            'utm_source' => $validated['utmSource'] ?? null,
            'utm_medium' => $validated['utmMedium'] ?? null,
            'utm_campaign' => $validated['utmCampaign'] ?? null,
            'utm_term' => $validated['utmTerm'] ?? null,
            'utm_content' => $validated['utmContent'] ?? null,
            'referrer' => $validated['referrer'] ?? null,
        ];
    }

    private function normalizedCustomerType(string $customerType): string
    {
        return $this->isBusinessType($customerType) ? 'Business' : 'Individual';
    }

    private function isBusinessType(string $customerType): bool
    {
        return in_array($customerType, [
            'Business',
            'Commercial',
            'Church / nonprofit',
            'Church / Non-Profit',
            'Builder / contractor',
        ], true);
    }
}
