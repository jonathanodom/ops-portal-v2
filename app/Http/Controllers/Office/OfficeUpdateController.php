<?php

namespace App\Http\Controllers\Office;

use App\Domain\OfficeUpdatePublisher;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublishOfficeUpdateRequest;
use App\Models\OfficeUpdate;
use App\Models\OrganizationMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class OfficeUpdateController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [OfficeUpdate::class, $organization]);
        $query = OfficeUpdate::query()->forOrganization($organization->id)->with('publishedBy');
        if (! $request->attributes->get('membership')->hasCapability('users.manage')) {
            $query->whereHas('recipients', fn ($query) => $query->where('user_id', $request->user()->id));
        }

        return view('office-updates.index', [
            'updates' => $query->orderByDesc('published_at')->orderByDesc('id')->paginate(25),
        ]);
    }

    public function create(Request $request): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('publish', [OfficeUpdate::class, $organization]);

        return view('office-updates.create', [
            'staff' => OrganizationMembership::query()
                ->where('organization_id', $organization->id)
                ->where('status', 'active')
                ->whereHas('user', fn ($query) => $query->where('status', 'active'))
                ->whereDoesntHave('roles', fn ($query) => $query->where('key', 'jarvis_service'))
                ->with('user')
                ->get()
                ->sortBy(fn ($membership) => $membership->user->name)
                ->values(),
        ]);
    }

    public function store(PublishOfficeUpdateRequest $request, OfficeUpdatePublisher $publisher): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('publish', [OfficeUpdate::class, $organization]);
        $update = $publisher->publish($organization, $request->user(), $request->validated());

        return redirect()->route('office-updates.show', $update)->with('status', 'Office Update published.');
    }

    public function show(Request $request, string $officeUpdate): View
    {
        $organization = $request->attributes->get('organization');
        $update = OfficeUpdate::query()
            ->forOrganization($organization->id)
            ->with('publishedBy')
            ->findOrFail($officeUpdate);
        Gate::authorize('view', $update);

        return view('office-updates.show', ['update' => $update]);
    }
}
