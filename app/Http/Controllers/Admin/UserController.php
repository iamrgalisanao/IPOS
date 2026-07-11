<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->with([
                'roles:id,name,description',
                'roles.permissions:id,name',
                'branches:id,name,branch_code',
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'status' => $user->status,
                'actor_type' => $user->actor_type,
                'has_pos_pin' => filled($user->pos_pin_hash),
                'roles' => $user->roles->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                ])->values(),
                'role_ids' => $user->roles->pluck('id')->values(),
                'branches' => $user->branches->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'branch_code' => $branch->branch_code,
                ])->values(),
                'branch_ids' => $user->branches->pluck('id')->values(),
                'permissions' => $user->roles
                    ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
                    ->unique()
                    ->sort()
                    ->values(),
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'roles' => Role::query()
                ->with('permissions:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'permissions' => $role->permissions->pluck('name')->sort()->values(),
                ]),
            'branches' => Branch::query()
                ->orderBy('name')
                ->get(['id', 'name', 'branch_code', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatedPayload($request);

        $user = User::create([
            'name' => $this->displayName($validated),
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'status' => $validated['status'],
            'actor_type' => 'tenant_user',
        ]);

        if (!empty($validated['pos_pin'])) {
            $user->setPosPin($validated['pos_pin']);
            $user->save();
        }

        $user->roles()->sync($validated['role_ids']);
        $user->branches()->sync($validated['branch_ids']);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User created successfully.');
    }

    public function update(Request $request, User $user)
    {
        $validated = $this->validatedPayload($request, $user);

        $user->forceFill([
            'name' => $this->displayName($validated),
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'status' => $validated['status'],
        ]);

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        if (!empty($validated['pos_pin'])) {
            $user->setPosPin($validated['pos_pin']);
        }

        $user->save();
        $user->roles()->sync($validated['role_ids']);
        $user->branches()->sync($validated['branch_ids']);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User updated successfully.');
    }

    protected function validatedPayload(Request $request, ?User $user = null): array
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'status' => ['required', Rule::in(['active', 'inactive', 'pending_activation'])],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['required', 'uuid', Rule::exists('roles', 'id')],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'pos_pin' => ['nullable', 'digits_between:4,6'],
        ]);

        if (Role::whereKey($validated['role_ids'])->count() !== count(array_unique($validated['role_ids']))) {
            throw ValidationException::withMessages([
                'role_ids' => 'One or more selected roles are not available for this tenant.',
            ]);
        }

        if (Branch::whereKey($validated['branch_ids'])->count() !== count(array_unique($validated['branch_ids']))) {
            throw ValidationException::withMessages([
                'branch_ids' => 'One or more selected branches are not available for this tenant.',
            ]);
        }

        return $validated;
    }

    protected function displayName(array $validated): string
    {
        return trim($validated['first_name'] . ' ' . $validated['last_name']);
    }
}
