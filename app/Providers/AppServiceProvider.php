<?php

namespace App\Providers;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\Visit;
use App\Policies\ContactPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\ServiceLocationPolicy;
use App\Policies\ServiceTicketPolicy;
use App\Policies\VisitPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(ServiceLocation::class, ServiceLocationPolicy::class);
        Gate::policy(ServiceTicket::class, ServiceTicketPolicy::class);
        Gate::policy(Visit::class, VisitPolicy::class);
    }
}
