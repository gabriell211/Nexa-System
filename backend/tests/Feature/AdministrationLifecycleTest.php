<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdministrationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedUser(Tenant $tenant, string $role): string
    {
        $user = User::query()->create([
            'name' => 'Test Operator',
            'email' => uniqid('operator-', true).'@example.test',
            'password' => 'secure-password-for-tests',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);

        return $user->createToken('test', ['api', 'tenant:'.$tenant->id])->plainTextToken;
    }

    public function test_owner_can_create_update_and_deactivate_customer_with_audit(): void
    {
        $tenant = Tenant::query()->create(['name' => 'First', 'slug' => 'first']);
        $token = $this->authorizedUser($tenant, 'owner');

        $id = $this->withToken($token)->postJson('/api/v1/customers', [
            'name' => 'Cliente um',
        ])->assertSuccessful()->json('data.id');

        $this->withToken($token)->putJson('/api/v1/customers/'.$id, [
            'name' => 'Cliente atualizado',
        ])->assertOk()->assertJsonPath('data.name', 'Cliente atualizado');

        $this->withToken($token)->deleteJson('/api/v1/customers/'.$id)->assertNoContent();
        $this->withToken($token)->getJson('/api/v1/customers/'.$id)
            ->assertOk()->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('customers', ['id' => $id, 'active' => false]);
        $this->assertSame(3, AuditEntry::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_read_only_role_cannot_mutate_records_or_read_audit(): void
    {
        $tenant = Tenant::query()->create(['name' => 'First', 'slug' => 'first']);
        $token = $this->authorizedUser($tenant, 'technician');

        $this->withToken($token)->postJson('/api/v1/customers', [
            'name' => 'Blocked',
        ])->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/audit-entries')->assertForbidden();
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customer_role_has_no_access_to_internal_admin_api(): void
    {
        $tenant = Tenant::query()->create(['name' => 'First', 'slug' => 'first']);
        $token = $this->authorizedUser($tenant, 'customer');

        $this->withToken($token)->getJson('/api/v1/dashboard')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/customers')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/printers')->assertForbidden();
    }

    public function test_audit_log_never_exposes_records_from_other_tenant(): void
    {
        $first = Tenant::query()->create(['name' => 'First', 'slug' => 'first']);
        $second = Tenant::query()->create(['name' => 'Second', 'slug' => 'second']);
        $firstToken = $this->authorizedUser($first, 'owner');
        $secondToken = $this->authorizedUser($second, 'owner');

        $this->withToken($firstToken)->postJson('/api/v1/customers', ['name' => 'Private'])->assertSuccessful();
        $this->withToken($secondToken)->getJson('/api/v1/audit-entries')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_printer_must_belong_to_active_customer_in_same_tenant(): void
    {
        $tenant = Tenant::query()->create(['name' => 'First', 'slug' => 'first']);
        $token = $this->authorizedUser($tenant, 'owner');
        $customer = $tenant->customers()->create(['name' => 'Disabled', 'active' => false]);

        $this->withToken($token)->postJson('/api/v1/printers', [
            'customer_id' => $customer->id,
            'manufacturer' => 'Kyocera',
            'model' => 'MA5500ifx',
        ])->assertUnprocessable();
    }
}
