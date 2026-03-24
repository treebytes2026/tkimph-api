<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    private const MANAGEABLE_ROLES = [
        User::ROLE_CUSTOMER,
        User::ROLE_RESTAURANT_OWNER,
        User::ROLE_RIDER,
    ];

    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->where('role', '!=', User::ROLE_ADMIN);

        if ($request->filled('role')) {
            $role = $request->string('role')->toString();
            if (in_array($role, self::MANAGEABLE_ROLES, true)) {
                $query->where('role', $role);
            }
        }

        if ($request->filled('search')) {
            $s = '%'.$request->string('search')->trim().'%';
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', $s)->orWhere('email', 'like', $s);
            });
        }

        $users = $query->orderByDesc('id')->paginate($request->integer('per_page', 15));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(self::MANAGEABLE_ROLES)],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['is_active'] = $data['is_active'] ?? true;

        $user = User::create($data);

        return response()->json($this->serializeUser($user), 201);
    }

    public function show(User $user): JsonResponse
    {
        $this->guardNotAdmin($user);

        return response()->json($this->serializeUser($user));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->guardNotAdmin($user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['sometimes', Rule::in(self::MANAGEABLE_ROLES)],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('password', $data) && $data['password'] === null) {
            unset($data['password']);
        }

        if (! empty($data['password'])) {
            // already hashed by cast when set on model
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json($this->serializeUser($user->fresh()));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->guardNotAdmin($user);

        if ($request->user()->id === $user->id) {
            abort(422, 'You cannot delete your own account.');
        }

        if ($user->restaurants()->exists()) {
            abort(422, 'Remove or reassign this partner\'s restaurants before deleting the account.');
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    public function toggleActive(User $user): JsonResponse
    {
        $this->guardNotAdmin($user);

        $user->update(['is_active' => ! $user->is_active]);

        return response()->json($this->serializeUser($user->fresh()));
    }

    private function guardNotAdmin(User $user): void
    {
        if ($user->isAdmin()) {
            abort(403, 'Admin accounts cannot be managed here.');
        }
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'phone' => $user->phone,
            'address' => $user->address,
            'is_active' => (bool) $user->is_active,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}
