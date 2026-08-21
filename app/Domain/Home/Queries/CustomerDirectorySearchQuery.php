<?php

namespace App\Domain\Home\Queries;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ServiceLocation;
use App\Support\Phone;

final class CustomerDirectorySearchQuery
{
    public const LIMIT_PER_GROUP = 8;

    public function search(Organization $organization, string $query): array
    {
        $term = trim($query);
        if (mb_strlen($term) < 2) {
            return ['query' => $term, 'searched' => false, 'customers' => collect(), 'contacts' => collect(), 'locations' => collect()];
        }

        $pattern = '%'.$this->escapeLike(mb_strtolower($term)).'%';
        $phone = Phone::normalize($term);

        $customers = Customer::query()->forOrganization($organization->id)
            ->where(fn ($builder) => $this->match($builder, ['display_name', 'legal_name', 'phone', 'email'], $pattern, $phone))
            ->orderBy('display_name')->orderBy('id')->limit(self::LIMIT_PER_GROUP)->get();
        $contacts = Contact::query()->where('organization_id', $organization->id)
            ->where(fn ($builder) => $this->match($builder, ['name', 'role', 'phone', 'email'], $pattern, $phone))
            ->with('customer:id,organization_id,display_name,status')->orderBy('name')->orderBy('id')->limit(self::LIMIT_PER_GROUP)->get();
        $locations = ServiceLocation::query()->where('organization_id', $organization->id)
            ->where(fn ($builder) => $this->match($builder, ['name', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code'], $pattern))
            ->with('customer:id,organization_id,display_name,status')->orderBy('name')->orderBy('id')->limit(self::LIMIT_PER_GROUP)->get();

        return compact('term', 'customers', 'contacts', 'locations') + ['query' => $term, 'searched' => true];
    }

    private function match($builder, array $fields, string $pattern, ?string $phone = null): void
    {
        foreach ($fields as $index => $field) {
            $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
            $builder->{$method}("LOWER({$field}) LIKE ? ESCAPE '!'", [$pattern]);
        }
        if ($phone !== null) {
            $builder->orWhere('phone_normalized', 'like', '%'.$phone.'%');
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
