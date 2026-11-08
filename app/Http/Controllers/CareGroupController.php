<?php

namespace App\Http\Controllers;

use App\Models\CareGroup;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CareGroupController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function index(): View
    {
        $users = User::orderBy('name')->orderBy('id')->get();

        return view('admin.groups', [
            'groups' => CareGroup::withCount('children')->orderBy('name')->get(),
            'systemAdministrators' => $users->filter(fn (User $user): bool => $user->isSystemAdministrator()),
            'usersByGroup' => $users->whereNotNull('group_id')->groupBy('group_id'),
            'unassignedUsers' => $users->whereNull('group_id')->where('is_admin', false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150', 'unique:care_groups,name']]);
        DB::transaction(function () use ($data): void {
            $group = CareGroup::create($data);
            $this->audit->record('group.created', $group, $data);
        });

        return back()->with('success', 'Gruppe angelegt.');
    }

    public function update(Request $request, CareGroup $group): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150', Rule::unique('care_groups')->ignore($group)]]);
        DB::transaction(function () use ($group, $data): void {
            $before = $group->name;
            $group->update($data);
            $this->audit->record('group.updated', $group, ['before' => $before, 'after' => $data['name']]);
        });

        return back()->with('success', 'Gruppe gespeichert.');
    }

    public function storeAdministrator(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'string', 'min:12', 'confirmed'], 'group_id' => ['nullable', 'integer', 'exists:care_groups,id']]);
        DB::transaction(function () use ($data): void {
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->is_admin = true;
            $user->group_id = isset($data['group_id']) ? (int) $data['group_id'] : null;
            $user->save();
            $this->audit->record('group.administrator_created', $user, ['name' => $user->name, 'email' => $user->email, 'group_id' => $user->group_id]);
        });

        return back()->with('success', 'Admin angelegt.');
    }

    public function assignAdministrator(Request $request): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id'], 'group_id' => ['required', 'integer', 'exists:care_groups,id']]);
        DB::transaction(function () use ($data): void {
            $administrators = User::where('is_admin', true)->whereNull('group_id')->orderBy('id')->lockForUpdate()->get();
            $user = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            if ($user->isSystemAdministrator() && $administrators->count() === 1) {
                throw ValidationException::withMessages(['user_id' => 'Das letzte Konto mit Gesamtzugriff muss erhalten bleiben.']);
            }
            $before = $user->group_id;
            $user->is_admin = true;
            $user->group_id = (int) $data['group_id'];
            $user->save();
            $this->audit->record('group.administrator_assigned', $user, ['before' => $before, 'group_id' => $user->group_id]);
        });

        return back()->with('success', 'Gruppenadmin zugeordnet.');
    }
}
