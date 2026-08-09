<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug', 'timezone', 'active'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function serviceLocations(): HasMany
    {
        return $this->hasMany(ServiceLocation::class);
    }

    public function serviceTickets(): HasMany
    {
        return $this->hasMany(ServiceTicket::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function billingSetting(): HasOne
    {
        return $this->hasOne(OrganizationBillingSetting::class);
    }

    public function laborRates(): HasMany
    {
        return $this->hasMany(BillingLaborRate::class);
    }
}
