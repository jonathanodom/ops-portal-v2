<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug', 'timezone', 'active', 'legal_name', 'email', 'phone', 'website', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country_code', 'full_logo_asset_id', 'mark_logo_asset_id'])]
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

    public function commercialLeadIntakes(): HasMany
    {
        return $this->hasMany(CommercialLeadIntake::class);
    }

    public function serviceTickets(): HasMany
    {
        return $this->hasMany(ServiceTicket::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function portalNotificationEvents(): HasMany
    {
        return $this->hasMany(PortalNotificationEvent::class);
    }

    public function portalNotificationPreferences(): HasMany
    {
        return $this->hasMany(PortalNotificationPreference::class);
    }

    public function officeUpdates(): HasMany
    {
        return $this->hasMany(OfficeUpdate::class);
    }

    public function billingSetting(): HasOne
    {
        return $this->hasOne(OrganizationBillingSetting::class);
    }

    public function laborRates(): HasMany
    {
        return $this->hasMany(BillingLaborRate::class);
    }

    public function catalogCategories(): HasMany
    {
        return $this->hasMany(CatalogCategory::class);
    }

    public function unitsOfMeasure(): HasMany
    {
        return $this->hasMany(UnitOfMeasure::class);
    }

    public function catalogServices(): HasMany
    {
        return $this->hasMany(CatalogService::class);
    }

    public function catalogProducts(): HasMany
    {
        return $this->hasMany(CatalogProduct::class);
    }

    public function catalogPackages(): HasMany
    {
        return $this->hasMany(CatalogPackage::class);
    }

    public function brandAssets(): HasMany
    {
        return $this->hasMany(OrganizationBrandAsset::class);
    }

    public function currentFullLogo(): BelongsTo
    {
        return $this->belongsTo(OrganizationBrandAsset::class, 'full_logo_asset_id');
    }

    public function currentMarkLogo(): BelongsTo
    {
        return $this->belongsTo(OrganizationBrandAsset::class, 'mark_logo_asset_id');
    }

    public function isBillingProfileComplete(): bool
    {
        return collect(['name', 'email', 'phone', 'address_line_1', 'city', 'state', 'postal_code'])
            ->every(fn (string $field): bool => filled($this->{$field}));
    }
}
