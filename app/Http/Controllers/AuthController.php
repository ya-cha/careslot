<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt([...$credentials, 'is_admin' => true])) {
            throw ValidationException::withMessages(['email' => 'Die Zugangsdaten sind ungültig.']);
        }
        $request->session()->forget('impersonator_id');
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, AuditService $audit): RedirectResponse
    {
        if ($request->session()->has('impersonator_id')) {
            $audit->record('impersonation.ended', $request->user(), ['reason' => 'logout']);
            Auth::logoutCurrentDevice();
        } else {
            Auth::logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
