<?php

namespace App\Domain\Commercial;

use App\Models\CommercialLeadIntake;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use App\Support\AuditRecorder;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LeadIntakeConverter
{
    public function __construct(
        private readonly OpportunityWorkflow $opportunities,
        private readonly AuditRecorder $audit,
    ) {}

    public function convert(Organization $organization, CommercialLeadIntake $intake, User $actor): Opportunity
    {
        return DB::transaction(function () use ($organization, $intake, $actor): Opportunity {
            $intake = CommercialLeadIntake::query()
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->findOrFail($intake->id);

            abort_unless(
                $organization->memberships()->where('user_id', $actor->id)->where('status', 'active')->exists(),
                404,
            );

            if ($intake->status === 'converted') {
                return Opportunity::query()
                    ->where('organization_id', $organization->id)
                    ->findOrFail($intake->opportunity_id);
            }

            if ($intake->status !== 'received') {
                throw ValidationException::withMessages([
                    'lead_intake' => 'Only received lead intakes may be converted.',
                ]);
            }

            [$customer, $contact, $strategy, $createdCustomer, $createdContact] = $this->resolveIdentity(
                $organization,
                $intake,
                $actor,
            );

            $title = Str::limit(
                trim($intake->service_interest).' — '.$customer->display_name,
                255,
                '',
            );

            $opportunity = $this->opportunities->create($organization, $actor, [
                'customer_id' => $customer->id,
                'primary_contact_id' => $contact?->id,
                'title' => $title,
                'priority' => 'normal',
                'estimated_value_cents' => 0,
                'lead_source' => 'website',
            ]);

            $intake->forceFill([
                'status' => 'converted',
                'opportunity_id' => $opportunity->id,
                'converted_at' => now(),
                'converted_by_id' => $actor->id,
            ])->save();

            $this->audit->record($organization, $actor, 'commercial_lead_intake.converted', $intake, [
                'opportunity_id' => $opportunity->id,
                'customer_id' => $customer->id,
                'contact_id' => $contact?->id,
                'match_strategy' => $strategy,
                'created_customer' => $createdCustomer,
                'created_contact' => $createdContact,
            ]);

            return $opportunity;
        });
    }

    /** @return array{Customer, ?Contact, string, bool, bool} */
    private function resolveIdentity(Organization $organization, CommercialLeadIntake $intake, User $actor): array
    {
        $email = Str::lower(trim((string) $intake->email));
        $phone = Phone::normalize($intake->phone);

        if ($email !== '') {
            $contacts = $this->contactCandidates($organization, fn (Builder $query) => $query->whereRaw('LOWER(TRIM(email)) = ?', [$email]));
            if ($contacts->count() > 1) {
                $this->ambiguous('email');
            }
            if ($contacts->count() === 1) {
                $contact = $contacts->first();

                return [$contact->customer, $contact, 'contact_email', false, false];
            }
        }

        if ($phone !== null) {
            $contacts = $this->contactCandidates($organization, fn (Builder $query) => $query->where('phone_normalized', $phone));
            if ($contacts->count() > 1) {
                $this->ambiguous('phone');
            }
            if ($contacts->count() === 1) {
                $contact = $contacts->first();

                return [$contact->customer, $contact, 'contact_phone', false, false];
            }
        }

        if ($email !== '') {
            $customers = $this->customerCandidates($organization, fn (Builder $query) => $query->whereRaw('LOWER(TRIM(email)) = ?', [$email]));
            if ($customers->count() > 1) {
                $this->ambiguous('email');
            }
            if ($customers->count() === 1) {
                return [$customers->first(), null, 'customer_email', false, false];
            }
        }

        if ($phone !== null) {
            $customers = $this->customerCandidates($organization, fn (Builder $query) => $query->where('phone_normalized', $phone));
            if ($customers->count() > 1) {
                $this->ambiguous('phone');
            }
            if ($customers->count() === 1) {
                return [$customers->first(), null, 'customer_phone', false, false];
            }
        }

        return $this->createIdentity($organization, $intake, $actor, $email, $phone);
    }

    private function contactCandidates(Organization $organization, callable $constraint)
    {
        return Contact::query()
            ->with('customer')
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->where($constraint)
            ->limit(2)
            ->get();
    }

    private function customerCandidates(Organization $organization, callable $constraint)
    {
        return Customer::query()
            ->where('organization_id', $organization->id)
            ->where($constraint)
            ->limit(2)
            ->get();
    }

    /** @return array{Customer, ?Contact, string, bool, bool} */
    private function createIdentity(
        Organization $organization,
        CommercialLeadIntake $intake,
        User $actor,
        string $email,
        ?string $phone,
    ): array {
        $isBusiness = Str::lower(trim((string) $intake->customer_type)) === 'business';
        $personName = trim($intake->first_name.' '.$intake->last_name);
        $company = trim((string) $intake->company);
        $displayName = $isBusiness ? $company : $personName;

        if ($displayName === '') {
            throw ValidationException::withMessages([
                'lead_intake' => 'The lead intake does not contain enough identity information to convert.',
            ]);
        }

        $customer = Customer::query()->create([
            'organization_id' => $organization->id,
            'type' => $isBusiness ? 'business' : 'individual',
            'display_name' => $displayName,
            'legal_name' => $isBusiness ? $company : null,
            'phone' => $intake->phone,
            'phone_normalized' => $phone,
            'email' => $email !== '' ? $email : null,
            'status' => 'active',
            'created_by_id' => $actor->id,
            'updated_by_id' => $actor->id,
        ]);
        $this->audit->record($organization, $actor, 'customer.created', $customer);

        $contact = null;
        if ($isBusiness && $personName !== '') {
            $contact = Contact::query()->create([
                'organization_id' => $organization->id,
                'customer_id' => $customer->id,
                'name' => $personName,
                'phone' => $intake->phone,
                'phone_normalized' => $phone,
                'email' => $email !== '' ? $email : null,
                'is_preferred' => true,
                'active' => true,
                'created_by_id' => $actor->id,
                'updated_by_id' => $actor->id,
            ]);
            $this->audit->record($organization, $actor, 'contact.created', $contact, [
                'customer_id' => $customer->id,
            ]);
        }

        return [$customer, $contact, 'created', true, $contact !== null];
    }

    private function ambiguous(string $field): never
    {
        throw ValidationException::withMessages([
            'lead_intake' => "Multiple active records match the lead {$field}; conversion requires manual resolution.",
        ]);
    }
}
