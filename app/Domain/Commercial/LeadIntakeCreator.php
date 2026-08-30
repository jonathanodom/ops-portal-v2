<?php

namespace App\Domain\Commercial;

use App\Models\CommercialLeadIntake;
use App\Models\Organization;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LeadIntakeCreator
{
    private const PERSISTED_FIELDS = [
        'first_name', 'last_name', 'phone', 'email', 'customer_type', 'zip', 'company',
        'service_interest', 'selected_plan', 'preferred_contact', 'timeline', 'details',
        'originating_page', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'referrer',
        'contact_consent_at', 'contact_consent_ip', 'contact_consent_version',
        'sms_consent_at', 'sms_consent_ip', 'sms_consent_version',
        'ip_address', 'user_agent', 'error_message',
    ];

    private const PAYLOAD_FIELDS = [
        'first_name', 'last_name', 'phone', 'email', 'customer_type', 'zip', 'company',
        'service_interest', 'selected_plan', 'preferred_contact', 'timeline', 'details',
        'originating_page', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'referrer',
        'contact_consent_version', 'sms_consent_version',
    ];

    public function create(Organization $organization, array $normalized): CommercialLeadIntake
    {
        $status = (string) ($normalized['status'] ?? 'received');
        $source = (string) ($normalized['source'] ?? 'website');

        if (! in_array($status, CommercialLeadIntake::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported lead intake status.');
        }

        if (! in_array($source, CommercialLeadIntake::SOURCES, true)) {
            throw new InvalidArgumentException('Unsupported lead intake source.');
        }

        $attributes = Arr::only($normalized, self::PERSISTED_FIELDS);
        $attributes['contact_consent_ip'] = filled($attributes['contact_consent_at'] ?? null)
            ? ($attributes['contact_consent_ip'] ?? null)
            : null;
        $attributes['contact_consent_version'] = filled($attributes['contact_consent_at'] ?? null)
            ? ($attributes['contact_consent_version'] ?? null)
            : null;
        $attributes['sms_consent_ip'] = filled($attributes['sms_consent_at'] ?? null)
            ? ($attributes['sms_consent_ip'] ?? null)
            : null;
        $attributes['sms_consent_version'] = filled($attributes['sms_consent_at'] ?? null)
            ? ($attributes['sms_consent_version'] ?? null)
            : null;

        $payload = $this->canonicalPayload($source, $attributes);
        $encodedPayload = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        return DB::transaction(fn (): CommercialLeadIntake => CommercialLeadIntake::query()->create([
            ...$attributes,
            'organization_id' => $organization->id,
            'status' => $status,
            'source' => $source,
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $encodedPayload),
            'received_at' => now(),
        ]));
    }

    private function canonicalPayload(string $source, array $attributes): array
    {
        $payload = ['source' => $source];

        foreach (self::PAYLOAD_FIELDS as $field) {
            $payload[$field] = $this->canonicalValue($attributes[$field] ?? null);
        }

        $payload['contact_consent'] = filled($attributes['contact_consent_at'] ?? null);
        $payload['sms_consent'] = filled($attributes['sms_consent_at'] ?? null);

        return $payload;
    }

    private function canonicalValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalValue(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalValue(...), $value);
    }
}
