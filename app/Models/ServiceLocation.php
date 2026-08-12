<?php

namespace App\Models;

use Database\Factories\ServiceLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'customer_id', 'primary_contact_id', 'name', 'address_line_1',
    'address_line_2', 'city', 'state', 'postal_code', 'timezone', 'access_instructions',
    'site_notes', 'is_primary', 'active', 'created_by_id', 'updated_by_id',
])]
class ServiceLocation extends Model
{
    /** @use HasFactory<ServiceLocationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'active' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function primaryContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'primary_contact_id');
    }

    public function serviceTickets(): HasMany
    {
        return $this->hasMany(ServiceTicket::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function serviceEnrollments(): HasMany
    {
        return $this->hasMany(CustomerServiceEnrollment::class);
    }

    public function formattedAddress(): string
    {
        return collect([
            $this->address_line_1,
            $this->address_line_2,
            $this->city,
            trim($this->state.' '.$this->postal_code),
        ])->filter()->implode(', ');
    }
}
