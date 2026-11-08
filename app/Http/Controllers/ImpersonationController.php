<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function show(Request $request): View
    {
        abort_unless($request->session()->has('impersonator_id'), 403);

        return view('admin.impersonation');
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        abort_if($request->user()->is($user), 403);

        $administratorId = $request->user()->id;
        $this->audit->record('impersonation.started', $user);

        Auth::logoutCurrentDevice();
        $request->session()->invalidate();
        Auth::login($user);
        $request->session()->put('impersonator_id', $administratorId);

        return redirect()->route($user->is_admin ? 'dashboard' : 'impersonation.show');
    }

    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($request->session()->has('impersonator_id'), 403);

        $administratorId = $request->session()->get('impersonator_id');
        $administrator = is_int($administratorId) ? User::find($administratorId) : null;
        $user = $request->user();

        Auth::logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (! $administrator?->isSystemAdministrator()) {
            return redirect()->route('login')->withErrors(['email' => 'Das ursprüngliche Administratorkonto ist nicht mehr verfügbar. Bitte melden Sie sich erneut an.']);
        }

        Auth::login($administrator);
        $this->audit->record('impersonation.ended', $user);

        return redirect()->route('groups.index')->with('success', 'Sie sind wieder mit Ihrem eigenen Konto angemeldet.');
    }
}
