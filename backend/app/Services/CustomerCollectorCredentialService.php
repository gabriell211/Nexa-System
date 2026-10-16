<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\CollectorAgent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CustomerCollectorCredentialService
{
    public function __construct(private readonly AuditWriter $audit) {}

    /**
     * Issue credentials for one new collector installation. The raw token is
     * returned only to the caller; the database and audit keep only its hash.
     *
     * @return array{customer_id:int,collector_id:string,collector_token:string}
     */
    public function issue(Tenant $tenant, User $actor, int $customerId): array
    {
        return DB::transaction(function () use ($tenant, $actor, $customerId): array {
            $customer = $tenant->customers()->lockForUpdate()->findOrFail($customerId);
            if (!$customer->active) {
                throw ValidationException::withMessages([
                    'customer' => 'Não é possível emitir credenciais para um cliente inativo.',
                ]);
            }

            $token = bin2hex(random_bytes(32));
            $collectorId = (string) Str::uuid();

            $agent = CollectorAgent::query()->create([
                'collector_id' => $collectorId,
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'token_hash' => hash('sha256', $token),
                'active' => true,
            ]);

            // Never persist the plaintext token in the audit trail.
            $this->audit->write($tenant, $actor, 'collector.credential.issued', $agent, null, [
                'collector_id' => $collectorId,
                'customer_id' => $customer->id,
            ]);

            return [
                'customer_id' => (int) $customer->id,
                'collector_id' => $collectorId,
                'collector_token' => $token,
            ];
        });
    }
}
