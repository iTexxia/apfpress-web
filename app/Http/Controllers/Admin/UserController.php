<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    private const MANAGEABLE_ROLES = ['catalog_manager', 'editor', 'fulfillment'];

    public function index(): JsonResponse
    {
        return response()->json(['data' => User::query()
            ->whereIn('role', ['owner', ...self::MANAGEABLE_ROLES])
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'editor' THEN 1 WHEN 'catalog_manager' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->resource($user))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(self::MANAGEABLE_ROLES)],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);
        unset($data['password_confirmation']);

        $user = User::query()->create($data + ['status' => 'active', 'email_verified_at' => now()]);
        $this->audit($request, 'staff.created', $user, null, $this->resource($user));

        return response()->json(['data' => $this->resource($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_if($user->role === 'owner' || $user->is($request->user()), 422, 'Owner accounts cannot be changed here.');
        $data = $request->validate([
            'role' => ['required', Rule::in(self::MANAGEABLE_ROLES)],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);
        $before = $this->resource($user);
        $user->update($data);
        if ($user->status === 'suspended') {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        $this->audit($request, 'staff.updated', $user, $before, $this->resource($user));

        return response()->json(['data' => $this->resource($user)]);
    }

    private function resource(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'editable' => $user->role !== 'owner',
        ];
    }

    private function audit(Request $request, string $action, User $user, ?array $before, array $after): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => $action,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
