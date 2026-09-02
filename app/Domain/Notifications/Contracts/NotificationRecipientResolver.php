<?php

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\NotificationAudience;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;

interface NotificationRecipientResolver
{
    /** @return Collection<int, User> */
    public function resolve(Organization $organization, NotificationAudience $audience): Collection;
}
