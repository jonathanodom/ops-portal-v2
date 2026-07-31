<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'customer_id', 'name', 'role', 'phone', 'phone_normalized',
    'email', 'is_preferred', 'active', 'created_by_id', 'updated_by_id',
])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_preferred' => 'boolean', 'active' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function serviceLocations(): HasMany
    {
        return $this->hasMany(ServiceLocation::class, 'primary_contact_id');
    }
}
