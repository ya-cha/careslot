<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function edit(User $user): View
    {
        return view('admin.user-form', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
        ]);

        DB::transaction(function () use ($user, $data): void {
            $before = $user->only(['name', 'email']);
            $user->fill($data);
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->save();
            $this->audit->record('user.updated', $user, ['before' => $before, 'after' => $data]);
        });

        return redirect()->route('users.edit', $user)->with('success', 'Benutzer gespeichert.');
    }

    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:12', 'confirmed']]);

        DB::transaction(function () use ($user, $data): void {
            $user->password = $data['password'];
            $user->remember_token = Str::random(60);
            $user->save();
            $this->audit->record('user.password_changed', $user);
        });

        return redirect()->route('users.edit', $user)->with('success', 'Passwort geändert.');
    }
}
