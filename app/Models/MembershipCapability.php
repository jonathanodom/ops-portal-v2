<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class MembershipCapability extends Pivot
{
    public $timestamps = false;

    protected $table = 'organization_membership_capability';
}
