<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'provider', 'actor_id', 'state_hash', 'pkce_verifier', 'environment', 'return_path', 'expires_at', 'consumed_at'])]
class PaymentProviderAuthorizationState extends Model
{
    protected $hidden = ['state_hash', 'pkce_verifier'];

    protected function casts(): array
    {
        return ['pkce_verifier' => 'encrypted', 'expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
