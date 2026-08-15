<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Support\OfficeDashboardSnapshot;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OfficeDashboardController extends Controller
{
    public function index(Request $request, OfficeDashboardSnapshot $dashboard): View
    {
        return view('office.home', [
            'dashboard' => $dashboard->for(
                $request->attributes->get('organization'),
                $request->attributes->get('membership'),
            ),
        ]);
    }
}
