<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Support\NewDayHomeSnapshot;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OfficeDashboardController extends Controller
{
    public function index(Request $request, NewDayHomeSnapshot $home): View
    {
        return view('office.home', [
            'home' => $home->for(
                $request->attributes->get('organization'),
                $request->attributes->get('membership'),
            ),
        ]);
    }
}
