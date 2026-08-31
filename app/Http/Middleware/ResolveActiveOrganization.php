<?php

namespace App\Http\Middleware;

use App\Models\CommercialLeadIntake;
use App\Models\OrganizationMembership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ResolveActiveOrganization
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $membership = OrganizationMembership::query()
            ->with(['organization.currentFullLogo', 'organization.currentMarkLogo'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('active', true))
            ->orderBy('id')
            ->first();

        abort_unless($membership, 403, 'Your account does not have an active organization membership.');

        $request->attributes->set('organization', $membership->organization);
        $request->attributes->set('membership', $membership);
        View::share('activeOrganization', $membership->organization);
        View::share('activeMembership', $membership);
        View::share('unresolvedLeadCount', str_starts_with($request->path(), 'office')
            && $membership->hasCapability('opportunities.view')
                ? CommercialLeadIntake::query()
                    ->forOrganization($membership->organization_id)
                    ->where('status', 'received')
                    ->count()
                : 0);

        return $next($request);
    }
}
