<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'tenant' => ['required', 'string', 'max:100'],
        ]);
        $user = User::query()->where('email', $data['email'])->first();
        if (!$user || !$user->active || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }
        $tenant = Tenant::query()->where('slug', $data['tenant'])->where('active', true)->first();
        if (!$tenant || !$user->tenants()->whereKey($tenant->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'Credenciais inválidas.']);
        }
        $token = $user->createToken('nexa-web', ['api', 'tenant:'.$tenant->id], now()->addHours(12));
        return response()->json([
            'token' => $token->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name],
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
        ]);
    }
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->only(['id','name','email']),
            'tenant' => $request->attributes->get('nexa_tenant')->only(['id','name','slug']),
            'role' => $request->attributes->get('nexa_role'),
        ]);
    }
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sessão encerrada.']);
    }
}
