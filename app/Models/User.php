<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'status', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function technicianProfile(): HasOne
    {
        return $this->hasOne(TechnicianProfile::class);
    }

    public function portalNotificationRecipients(): HasMany
    {
        return $this->hasMany(PortalNotificationRecipient::class);
    }

    public function portalNotificationPreferences(): HasMany
    {
        return $this->hasMany(PortalNotificationPreference::class);
    }

    public function browserPushSubscriptions(): HasMany
    {
        return $this->hasMany(BrowserPushSubscription::class);
    }

    public function officeUpdatesPublished(): HasMany
    {
        return $this->hasMany(OfficeUpdate::class, 'published_by_id');
    }

    public function officeUpdateRecipients(): HasMany
    {
        return $this->hasMany(OfficeUpdateRecipient::class);
    }
}
