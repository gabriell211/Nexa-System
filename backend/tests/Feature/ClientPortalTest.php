<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private function member(Tenant $tenant, string $role = 'customer', string $scope = 'assigned'): User
    {
        $user = User::query()->create([
            'name' => 'Portal access',
            'email' => (string) Str::uuid().'@example.test',
            'password' => 'portal-password-secure',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role, 'customer_scope' => $scope]);
        return $user;
    }

    private function login(User $user, Tenant $tenant): void
    {
        $this->postJson('/api/v1/browser/auth/login', [
            'tenant' => $tenant->slug,
            'email' => $user->email,
            'password' => 'portal-password-secure',
        ])->assertOk();
    }

    private function grant(User $user, Tenant $tenant, int $customerId): void
    {
        DB::table('customer_user_access')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'customer_id' => $customerId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_assigned_customer_can_see_only_own_ip_and_real_meter_strings(): void
    {
        $tenant = Tenant::query()->create(['name' => 'MPS', 'slug' => 'mps']);
        $foreign = Tenant::query()->create(['name' => 'Outside', 'slug' => 'outside']);
        $alpha = $tenant->customers()->create(['name' => 'Alpha']);
        $beta = $tenant->customers()->create(['name' => 'Beta']);
        $outside = $foreign->customers()->create(['name' => 'Foreign customer']);

        $mine = $tenant->printers()->create([
            'customer_id' => $alpha->id, 'manufacturer' => 'HP',
            'model' => 'LaserJet 400 M401dne', 'serial_number' => 'BRDSFD9G9S',
            'ip_address' => '192.168.0.64', 'active' => true,
        ]);
        $hidden = $tenant->printers()->create([
            'customer_id' => $beta->id, 'manufacturer' => 'Ricoh',
            'model' => 'SP 3710SF', 'ip_address' => '192.168.0.151', 'active' => true,
        ]);
        $foreign->printers()->create([
            'customer_id' => $outside->id, 'manufacturer' => 'Canon', 'model' => 'Foreign',
            'ip_address' => '10.100.2.1',
        ]);
        DB::table('printer_readings')->insert([
            'tenant_id' => $tenant->id, 'printer_id' => $mine->id,
            'sample_id' => (string) Str::uuid(), 'meter_total' => '9007199254740993',
            'meter_mono' => '150', 'meter_color' => null, 'meter_scan' => null,
            'collected_at' => '2026-10-17 12:00:00', 'received_at' => '2026-10-17 12:01:00',
        ]);

        $user = $this->member($tenant);
        $this->grant($user, $tenant, $alpha->id);
        $this->login($user, $tenant);

        $this->getJson('/api/v1/browser/client/printers?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.ip_address', '192.168.0.64')
            ->assertJsonPath('data.0.serial_number', 'BRDSFD9G9S')
            ->assertJsonPath('data.0.latest_reading.meter_total', '9007199254740993')
            ->assertJsonPath('data.0.latest_reading.meter_color', null)
            ->assertJsonMissing(['model' => 'SP 3710SF'])
            ->assertJsonMissing(['ip_address' => '192.168.0.151'])
            ->assertJsonMissing(['ip_address' => '10.100.2.1']);
        $this->getJson('/api/v1/browser/client/summary')->assertOk()
            ->assertJsonPath('printers', 1)
            ->assertJsonPath('active', 1)
            ->assertJsonPath('printers_with_readings', 1)
            ->assertJsonPath('assigned_customers', 1);
        $this->getJson('/api/v1/browser/client/printers?q=SP%203710SF')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/browser/client/printers?customer_id='.$beta->id)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
        $this->getJson('/api/v1/browser/printers')->assertForbidden();
        $this->postJson('/api/v1/browser/printers', [
            'customer_id' => $alpha->id, 'manufacturer' => 'Test', 'model' => 'Test',
        ])->assertForbidden();

        DB::table('customer_user_access')->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)->delete();
        $this->getJson('/api/v1/browser/client/printers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/browser/client/summary')->assertOk()->assertJsonPath('printers', 0);
        $this->assertDatabaseHas('printers', ['id' => $hidden->id]);
    }

    public function test_unassigned_or_full_scope_customer_is_not_treated_as_provider_admin(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Internal client']);
        $tenant->printers()->create([
            'customer_id' => $customer->id, 'manufacturer' => 'Xerox', 'model' => 'Sensitive',
        ]);
        $user = $this->member($tenant, 'customer', 'all');
        $this->login($user, $tenant);
        $this->getJson('/api/v1/browser/client/printers')->assertForbidden();
        $this->getJson('/api/v1/browser/client/summary')->assertForbidden();

        $this->postJson('/api/v1/browser/auth/logout')->assertOk();
        Auth::forgetGuards();
        $user2 = $this->member($tenant, 'customer', 'assigned');
        $this->login($user2, $tenant);
        $this->getJson('/api/v1/browser/client/printers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/browser/client/summary')->assertOk()
            ->assertJsonPath('printers', 0)->assertJsonPath('assigned_customers', 0);
    }

    public function test_unauthenticated_and_internal_roles_cannot_query_customer_endpoint(): void
    {
        $this->getJson('/api/v1/browser/client/printers')->assertUnauthorized();
        $this->getJson('/api/v1/browser/client/summary')->assertUnauthorized();

        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $owner = $this->member($tenant, 'owner', 'all');
        $this->login($owner, $tenant);
        $this->getJson('/api/v1/browser/client/printers')->assertForbidden();
        $this->getJson('/api/v1/browser/client/summary')->assertForbidden();
    }
}
