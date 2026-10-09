<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\BrowserLoginRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class BrowserAuthController extends Controller
{
    public function csrf(Request $request): JsonResponse
    {
        return response()->json([
            'csrf_token' => $request->session()->token(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function login(BrowserLoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        if (!$user || !$user->active || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }

        $tenant = Tenant::query()
            ->where('slug', $data['tenant'])->where('active', true)->first();
        $membership = $tenant ? $user->tenants()->whereKey($tenant->id)->first() : null;

        if ($membership === null) {
            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('nexa_tenant_id', (int) $tenant->id);

        return response()->json([
            'user' => $user->only(['id', 'name', 'email']),
            'tenant' => $tenant->only(['id', 'name', 'slug']),
            'role' => $membership->pivot->role,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sessão encerrada.'])
            ->header('Cache-Control', 'no-store, private');
    }
}
