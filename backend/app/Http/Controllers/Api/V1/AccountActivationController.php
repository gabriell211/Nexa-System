<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class AccountActivationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant' => ['required','string','max:100'],
            'token' => ['required','string','regex:/^[a-f0-9]{64}$/'],
            'password' => ['required','confirmed',Password::min(12)->mixedCase()->numbers()],
        ]);
        $completed = DB::transaction(function () use ($data): bool {
            $invite = DB::table('account_invitations')
                ->join('tenants', 'tenants.id', '=', 'account_invitations.tenant_id')
                ->where('tenants.slug', $data['tenant'])
                ->where('tenants.active', true)
                ->where('account_invitations.token_sha256', hash('sha256', $data['token']))
                ->select('account_invitations.*')
                ->lockForUpdate()->first();

            if (!$invite || $invite->consumed_at !== null || now()->greaterThan($invite->expires_at)) {
                return false;
            }
            $member = DB::table('tenant_user')
                ->where('tenant_id', $invite->tenant_id)
                ->where('user_id', $invite->user_id)->exists();
            $user = User::query()->whereKey($invite->user_id)->lockForUpdate()->first();
            if (!$member || !$user || $user->active) {
                return false;
            }
            $user->password = $data['password'];
            $user->active = true;
            $user->save();
            DB::table('account_invitations')->where('id', $invite->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
            return true;
        }, 3);
        if (!$completed) {
            throw ValidationException::withMessages([
                'token' => 'Convite inválido, já utilizado ou expirado. Solicite um novo convite à sua prestadora.',
            ]);
        }
        return response()->json(['message' => 'Conta ativada. Entre com seu e-mail, senha e identificador de empresa.'])
            ->header('Cache-Control','no-store, private');
    }
}
