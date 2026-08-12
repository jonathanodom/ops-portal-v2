<?php

namespace App\Domain;

use App\Models\CatalogService;
use App\Models\CatalogServiceVariant;
use App\Models\Customer;
use App\Models\CustomerServiceEnrollment;
use App\Models\ServiceLocation;
use App\Models\User;
use App\Support\AuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerServiceEnrollmentWorkflow
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $values */
    public function create(Customer $customer, User $actor, array $values): CustomerServiceEnrollment
    {
        return DB::transaction(function () use ($customer, $actor, $values): CustomerServiceEnrollment {
            $customer = Customer::query()->forOrganization($customer->organization_id)->lockForUpdate()->findOrFail($customer->id);
            if ($customer->status !== 'active') {
                throw ValidationException::withMessages(['customer' => 'Recurring Services require an active Customer.']);
            }
            [$service, $variant, $location] = $this->resolveSources($customer, $values);
            $scopeKey = $this->scopeKey($customer, $service, $location, $variant);
            $price = $variant?->price_override_cents ?? $service->default_price_cents;
            if ($price === null) {
                throw ValidationException::withMessages(['catalog_service_id' => 'The recurring Service requires a default or Variant amount before enrollment.']);
            }

            try {
                $enrollment = CustomerServiceEnrollment::query()->create([
                    'organization_id' => $customer->organization_id,
                    'customer_id' => $customer->id,
                    'service_location_id' => $location?->id,
                    'catalog_service_id' => $service->id,
                    'catalog_service_variant_id' => $variant?->id,
                    'status' => 'active',
                    'start_date' => $values['start_date'],
                    'end_date' => $values['end_date'] ?? null,
                    'next_billing_date' => $values['next_billing_date'] ?? null,
                    'billing_amount_cents' => $values['billing_amount_cents'] ?? $price,
                    'billing_amount_override_reason' => $values['billing_amount_override_reason'] ?? null,
                    'billing_cadence' => $service->billing_cadence,
                    'billing_interval' => $service->billing_interval,
                    'taxable_snapshot' => $service->taxable,
                    'service_code_snapshot' => $service->service_code,
                    'service_name_snapshot' => $service->name,
                    'service_description_snapshot' => $service->customer_description,
                    'service_unit_code_snapshot' => $service->salesUom->code,
                    'service_unit_name_snapshot' => $service->salesUom->name,
                    'variant_code_snapshot' => $variant?->code,
                    'variant_label_snapshot' => $variant?->customer_label ?: $variant?->label,
                    'internal_notes' => $values['internal_notes'] ?? null,
                    'current_scope_key' => $scopeKey,
                    'status_changed_at' => now(),
                    'status_changed_by_id' => $actor->id,
                    'created_by_id' => $actor->id,
                    'updated_by_id' => $actor->id,
                ]);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw ValidationException::withMessages(['catalog_service_id' => 'This Customer and location already have a current enrollment for the selected Service and Variant.']);
                }

                throw $exception;
            }
            $this->audit->record($customer->organization, $actor, 'subscription.created', $enrollment, [
                'enrollment_id' => $enrollment->id,
                'customer_id' => $customer->id,
                'service_location_id' => $location?->id,
                'catalog_service_id' => $service->id,
                'catalog_service_variant_id' => $variant?->id,
                'status' => 'active',
                'changed_fields' => array_keys($values),
            ]);

            return $enrollment;
        });
    }

    /** @param array<string, mixed> $values */
    public function update(CustomerServiceEnrollment $enrollment, User $actor, array $values): CustomerServiceEnrollment
    {
        return DB::transaction(function () use ($enrollment, $actor, $values): CustomerServiceEnrollment {
            $enrollment = CustomerServiceEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            if ($enrollment->status === 'canceled') {
                throw ValidationException::withMessages(['status' => 'Canceled enrollments are immutable. Create a new enrollment to restart this Service.']);
            }
            $changed = collect($values)->filter(fn ($value, $field) => $enrollment->{$field} != $value)->keys()->all();
            $enrollment->update($values + ['updated_by_id' => $actor->id]);
            $this->audit->record($enrollment->organization, $actor, 'subscription.updated', $enrollment, [
                'enrollment_id' => $enrollment->id,
                'customer_id' => $enrollment->customer_id,
                'changed_fields' => $changed,
            ]);
            if (in_array('billing_amount_cents', $changed, true)) {
                $this->audit->record($enrollment->organization, $actor, 'subscription.amount_overridden', $enrollment, [
                    'enrollment_id' => $enrollment->id,
                    'customer_id' => $enrollment->customer_id,
                    'changed_fields' => ['billing_amount_cents', 'billing_amount_override_reason'],
                ]);
            }

            return $enrollment;
        });
    }

    public function transition(CustomerServiceEnrollment $enrollment, User $actor, string $status): CustomerServiceEnrollment
    {
        return DB::transaction(function () use ($enrollment, $actor, $status): CustomerServiceEnrollment {
            $enrollment = CustomerServiceEnrollment::query()->lockForUpdate()->findOrFail($enrollment->id);
            $allowed = match ($enrollment->status) {
                'active' => ['paused', 'canceled'],
                'paused' => ['active', 'canceled'],
                default => [],
            };
            if (! in_array($status, $allowed, true)) {
                $this->audit->record($enrollment->organization, $actor, 'subscription.transition_rejected', $enrollment, [
                    'enrollment_id' => $enrollment->id,
                    'from_status' => $enrollment->status,
                    'to_status' => $status,
                ]);
                throw ValidationException::withMessages(['status' => 'That enrollment status change is not allowed.']);
            }

            $from = $enrollment->status;
            $updates = [
                'status' => $status,
                'status_changed_at' => now(),
                'status_changed_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ];
            if ($status === 'canceled') {
                $updates += [
                    'current_scope_key' => null,
                    'canceled_at' => now(),
                    'canceled_by_id' => $actor->id,
                    'end_date' => $enrollment->end_date ?? now()->toDateString(),
                    'next_billing_date' => null,
                ];
            }
            $enrollment->update($updates);
            $this->audit->record($enrollment->organization, $actor, 'subscription.status_changed', $enrollment, [
                'enrollment_id' => $enrollment->id,
                'customer_id' => $enrollment->customer_id,
                'from_status' => $from,
                'to_status' => $status,
                'changed_fields' => array_keys($updates),
            ]);

            return $enrollment;
        });
    }

    /** @param array<string, mixed> $values @return array{CatalogService, ?CatalogServiceVariant, ?ServiceLocation} */
    private function resolveSources(Customer $customer, array $values): array
    {
        $service = CatalogService::query()->forOrganization($customer->organization_id)
            ->where('active', true)->where('pricing_model', 'recurring')->with('salesUom')->find($values['catalog_service_id']);
        if (! $service || ! $service->billing_cadence || ! $service->billing_interval) {
            throw ValidationException::withMessages(['catalog_service_id' => 'Choose an active recurring Service from this Organization.']);
        }
        $location = null;
        if ($values['service_location_id'] ?? null) {
            $location = ServiceLocation::query()->where('organization_id', $customer->organization_id)
                ->where('customer_id', $customer->id)->where('active', true)->find($values['service_location_id']);
            if (! $location) {
                throw ValidationException::withMessages(['service_location_id' => 'Choose an active location belonging to this Customer.']);
            }
        }
        $variant = null;
        if ($values['catalog_service_variant_id'] ?? null) {
            $variant = CatalogServiceVariant::query()->forOrganization($customer->organization_id)
                ->where('catalog_service_id', $service->id)->where('active', true)->find($values['catalog_service_variant_id']);
            if (! $variant) {
                throw ValidationException::withMessages(['catalog_service_variant_id' => 'Choose an active Variant belonging to this Service.']);
            }
        }

        return [$service, $variant, $location];
    }

    private function scopeKey(Customer $customer, CatalogService $service, ?ServiceLocation $location, ?CatalogServiceVariant $variant): string
    {
        return hash('sha256', implode(':', [$customer->organization_id, $customer->id, $location?->id ?? 0, $service->id, $variant?->id ?? 0]));
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? $exception->getCode()), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
