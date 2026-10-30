<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\AccountInvitationRequest;
use App\Models\User;
use App\Services\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * New accounts are disabled until the invitee chooses a password using a
 * one-time, 24h activation token. The token is only returned to the issuer
 * once; no plaintext credentials or invitation tokens are stored or audited.
 */
final class AccountProvisioningController extends Controller
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function store(AccountInvitationRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('nexa_tenant');
        $data = $request->validated();
        if ($data['account_type'] === 'administrator'
            && $request->attributes->get('nexa_role') !== 'owner') {
            abort(403, 'Somente o proprietário pode convidar administradores.');
        }

        $email = Str::lower(trim($data['email']));
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Esta conta já existe. Solicite ao administrador a associação apropriada.',
            ]);
        }
        $ids = array_values(array_unique(array_map('intval', $data['customer_ids'] ?? [])));
        sort($ids);
        $valid = $tenant->customers()->whereIn('id', $ids)
            ->where('active', true)->count();
        if ($valid !== count($ids)) {
            throw ValidationException::withMessages([
                'customer_ids' => 'Selecione somente clientes ativos da empresa atual.',
            ]);
        }
        $role = match ($data['account_type']) {
            'customer' => 'customer',
            'collaborator' => 'technician',
            'administrator' => 'admin',
        };
        $scope = $role === 'admin' ? 'all' : 'assigned';
        $token = bin2hex(random_bytes(32));
        $user = DB::transaction(function () use ($tenant, $request, $data, $email, $ids, $role, $scope, $token): User {
            $user = User::query()->create([
                'name' => trim($data['name']),
                'email' => $email,
                'active' => false,
                'password' => bin2hex(random_bytes(40)),
            ]);
            $tenant->users()->attach($user->id, [
                'role' => $role, 'customer_scope' => $scope,
            ]);
            if ($ids !== []) {
                DB::table('customer_user_access')->insert(array_map(
                    static fn (int $id): array => [
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                        'customer_id' => $id,
                        'created_at' => now(), 'updated_at' => now(),
                    ], $ids
                ));
            }
            if ($role === 'customer') {
                DB::table('customer_account_settings')->insert([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'view_reports' => (bool) ($data['view_reports'] ?? true),
                    'view_other_tickets' => (bool) ($data['view_other_tickets'] ?? false),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('account_invitations')->insert([
                'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'issued_by_user_id' => $request->user()->id,
                'token_sha256' => hash('sha256', $token),
                'expires_at' => now()->addHours(24),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->write($tenant, $request->user(), 'account.invited', $user, null, [
                'account_type' => $data['account_type'], 'role' => $role,
                'customer_ids' => $ids, 'activation_required' => true,
            ]);
            return $user;
        });

        return response()->json([
            'user' => $user->only(['id','name','email','active']),
            'role' => $role, 'scope' => $scope,
            'customer_ids' => $ids,
            'invitation' => ['token' => $token, 'tenant' => $tenant->slug,
                'expires_at' => now()->addHours(24)->toIso8601String()],
            'note' => 'Envie o link ao destinatário por canal seguro. Nenhum e-mail foi disparado automaticamente.',
        ], 201)->header('Cache-Control', 'no-store, private');
    }
}
