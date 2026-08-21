<?php

namespace App\Http\Controllers\Office;

use App\Domain\Home\Queries\CustomerDirectorySearchQuery;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class OfficeSearchController extends Controller
{
    public function __invoke(Request $request, CustomerDirectorySearchQuery $search): View
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('viewAny', [Customer::class, $organization]);

        return view('office.search.index', $search->search($organization, (string) $request->query('q', '')));
    }
}
