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

final class MobileTechnicianAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'=>['required','email','max:255'],
            'password'=>['required','string'],
            'tenant'=>['required','string','max:100'],
        ]);
        $user = User::query()->where('email', $data['email'])->where('active', true)->first();
        $tenant = Tenant::query()->where('slug', $data['tenant'])->where('active', true)->first();
        $membership = $user && $tenant ? $user->tenants()->whereKey($tenant->id)->first() : null;
        if (!$user || !Hash::check($data['password'], $user->password)
            || !$membership || $membership->pivot->role !== 'technician') {
            throw ValidationException::withMessages(['email'=>'Credenciais inválidas para o aplicativo técnico.']);
        }
        $token = $user->createToken('nexa-technician-mobile', [
            'api','tenant:'.$tenant->id,'mobile:technician',
        ], now()->addHours(12));

        return response()->json([
            'token'=>$token->plainTextToken,
            'expires_at'=>$token->accessToken->expires_at?->toIso8601String(),
            'user'=>$user->only(['id','name']),
            'tenant'=>$tenant->only(['id','name','slug']),
        ])->header('Cache-Control','no-store, private');
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user'=>$request->user()->only(['id','name','email']),
            'tenant'=>$request->attributes->get('nexa_tenant')->only(['id','name','slug']),
        ])->header('Cache-Control','no-store, private');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message'=>'Sessão encerrada.'])
            ->header('Cache-Control','no-store, private');
    }
}
