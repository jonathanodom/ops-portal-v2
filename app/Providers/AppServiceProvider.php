<?php

namespace App\Providers;

use App\Contracts\SquareConnectionClient;
use App\Contracts\StripeConnectionClient;
use App\Domain\Notifications\Contracts\NotificationRecipientResolver;
use App\Domain\Notifications\EloquentNotificationRecipientResolver;
use App\Domain\Projects\Contracts\CustomerDirectory;
use App\Domain\Projects\Contracts\ServiceOperationsDirectory;
use App\Domain\Projects\Support\EloquentCustomerDirectory;
use App\Domain\Projects\Support\EloquentServiceOperationsDirectory;
use App\Models\CommercialDocument;
use App\Models\CommercialLeadIntake;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerServiceEnrollment;
use App\Models\Invoice;
use App\Models\Opportunity;
use App\Models\Project;
use App\Models\ServiceLocation;
use App\Models\ServiceTicket;
use App\Models\Visit;
use App\Payments\SquareOAuthConnectionClient;
use App\Payments\StripeOAuthConnectionClient;
use App\Policies\CommercialDocumentPolicy;
use App\Policies\CommercialLeadIntakePolicy;
use App\Policies\ContactPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\CustomerServiceEnrollmentPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\OpportunityPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ServiceLocationPolicy;
use App\Policies\ServiceTicketPolicy;
use App\Policies\VisitPolicy;
use App\Support\IncidentRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SquareConnectionClient::class, SquareOAuthConnectionClient::class);
        $this->app->bind(StripeConnectionClient::class, StripeOAuthConnectionClient::class);
        $this->app->bind(CustomerDirectory::class, EloquentCustomerDirectory::class);
        $this->app->bind(ServiceOperationsDirectory::class, EloquentServiceOperationsDirectory::class);
        $this->app->bind(NotificationRecipientResolver::class, EloquentNotificationRecipientResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());
        RateLimiter::for('jarvis-api', function (Request $request): Limit {
            $organizationId = $request->attributes->get('organization')?->id ?? 'none';
            $identity = $request->user()?->getAuthIdentifier() ?? $request->ip();
            $isRead = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
            $limit = $isRead
                ? max(1, (int) config('jarvis.api_read_limit_per_minute', 120))
                : max(1, (int) config('jarvis.api_write_limit_per_minute', 30));

            return Limit::perMinute($limit)->by("jarvis-api:{$organizationId}:{$identity}:".($isRead ? 'read' : 'write'));
        });
        RateLimiter::for('public-leads', fn (Request $request): Limit => Limit::perMinute(
            max(1, (int) config('lead-intake.rate_limit_per_minute', 5)),
        )->by('public-leads:'.$request->ip()));
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(CommercialDocument::class, CommercialDocumentPolicy::class);
        Gate::policy(CommercialLeadIntake::class, CommercialLeadIntakePolicy::class);
        Gate::policy(CustomerServiceEnrollment::class, CustomerServiceEnrollmentPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Opportunity::class, OpportunityPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(ServiceLocation::class, ServiceLocationPolicy::class);
        Gate::policy(ServiceTicket::class, ServiceTicketPolicy::class);
        Gate::policy(Visit::class, VisitPolicy::class);
        Queue::failing(function (JobFailed $event): void {
            app(IncidentRecorder::class)->record(null, null, 'queue_failure', 'error', null, [
                'connection' => $event->connectionName,
                'job_class' => $event->job->resolveName(),
                'reason_code' => class_basename($event->exception),
            ]);
        });
    }
}
