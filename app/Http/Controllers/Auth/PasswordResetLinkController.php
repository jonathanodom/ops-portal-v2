<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    public function create()
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        $user = User::query()
            ->where('email', $request->string('email'))
            ->where('status', 'active')
            ->whereHas('memberships', fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('organization', fn ($organization) => $organization->where('active', true)))
            ->first();

        if ($user) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', 'If an active account matches that email, a reset link has been sent.');
    }
}
