<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\MobileTechnicianLoginRequest;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class MobileTechnicianAuthController extends Controller
{
    public function login(MobileTechnicianLoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $tenant = Tenant::query()->where('slug', $data['tenant'])->where('active', true)->first();
        $user = $tenant?->users()
            ->wherePivot('role', 'technician')
            ->wherePivot('login_name', $data['login_name'])
            ->where('users.active', true)
            ->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login_name' => 'Credenciais inválidas para o aplicativo técnico.',
            ]);
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
