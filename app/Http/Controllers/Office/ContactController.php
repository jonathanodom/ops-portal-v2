<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Support\AuditRecorder;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function create(Request $request, string $customer): View
    {
        $customer = $this->customer($request, $customer);
        Gate::authorize('create', [Contact::class, $customer]);

        return view('office.contacts.create', compact('customer'));
    }

    public function store(Request $request, string $customer, AuditRecorder $audit): RedirectResponse
    {
        $customer = $this->customer($request, $customer);
        Gate::authorize('create', [Contact::class, $customer]);
        $data = $this->validated($request);

        $contact = DB::transaction(function () use ($request, $customer, $data, $audit): Contact {
            if ($data['is_preferred']) {
                Contact::query()->where('customer_id', $customer->id)->lockForUpdate()->get();
                $customer->contacts()->update(['is_preferred' => false]);
            }

            $contact = $customer->contacts()->create(array_merge($data, [
                'organization_id' => $customer->organization_id,
                'phone_normalized' => Phone::normalize($data['phone'] ?? null),
                'created_by_id' => $request->user()->id,
                'updated_by_id' => $request->user()->id,
            ]));
            $audit->record($this->organization($request), $request->user(), 'contact.created', $contact, [
                'customer_id' => $customer->id,
                'is_preferred' => $contact->is_preferred,
            ]);

            return $contact;
        });

        return redirect()->route('office.customers.show', $customer)->with('status', "Contact {$contact->name} added.");
    }

    public function edit(Request $request, string $customer, string $contact): View
    {
        $customer = $this->customer($request, $customer);
        $contact = $this->contact($customer, $contact);
        Gate::authorize('update', $contact);

        return view('office.contacts.edit', compact('customer', 'contact'));
    }

    public function update(Request $request, string $customer, string $contact, AuditRecorder $audit): RedirectResponse
    {
        $customer = $this->customer($request, $customer);
        $contact = $this->contact($customer, $contact);
        Gate::authorize('update', $contact);
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $customer, $contact, $data, $audit): void {
            Contact::query()->where('customer_id', $customer->id)->lockForUpdate()->get();
            if ($data['is_preferred']) {
                $customer->contacts()->whereKeyNot($contact->id)->update(['is_preferred' => false]);
            }
            if (! $data['active']) {
                $data['is_preferred'] = false;
                $customer->serviceLocations()->where('primary_contact_id', $contact->id)->update(['primary_contact_id' => null]);
            }

            $before = $contact->getAttributes();
            $contact->update(array_merge($data, [
                'phone_normalized' => Phone::normalize($data['phone'] ?? null),
                'updated_by_id' => $request->user()->id,
            ]));
            $changed = array_keys(array_diff_assoc($contact->getAttributes(), $before));
            $audit->record($this->organization($request), $request->user(), 'contact.updated', $contact, [
                'customer_id' => $customer->id,
                'changed_fields' => array_values(array_diff($changed, ['phone_normalized', 'updated_at'])),
            ]);
        });

        return redirect()->route('office.customers.show', $customer)->with('status', 'Contact updated.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'is_preferred' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['is_preferred'] = $request->boolean('is_preferred');
        $data['active'] = $request->boolean('active', true);

        return $data;
    }

    private function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    private function customer(Request $request, string $id): Customer
    {
        return Customer::query()->forOrganization($this->organization($request)->id)->findOrFail($id);
    }

    private function contact(Customer $customer, string $id): Contact
    {
        return $customer->contacts()->where('organization_id', $customer->organization_id)->findOrFail($id);
    }
}
