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

final class PrinterInventoryDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function user(Tenant $tenant, string $role): User
    {
        $user = User::query()->create([
            'name' => 'Inventory tester',
            'email' => (string) Str::uuid().'@example.test',
            'password' => 'strong-secure-test-password',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);
        return $user;
    }

    private function login(Tenant $tenant, User $user): void
    {
        $this->postJson('/api/v1/browser/auth/login', [
            'tenant' => $tenant->slug, 'email' => $user->email,
            'password' => 'strong-secure-test-password',
        ])->assertOk();
    }

    public function test_admin_records_verified_metadata_and_both_histories_with_null_unknown_defaults(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Strawplast']);
        $printer = $tenant->printers()->create([
            'customer_id' => $customer->id, 'manufacturer' => 'Brother',
            'model' => 'HL-5250DN', 'serial_number' => 'M8J897432',
        ]);
        $this->login($tenant, $this->user($tenant, 'owner'));

        $this->getJson('/api/v1/browser/printers/'.$printer->id)
            ->assertOk()->assertJsonPath('data.asset_number', null)
            ->assertJsonPath('data.mac_address', null)
            ->assertJsonPath('data.owner_type', 'unknown')
            ->assertJsonPath('data.color_capability', 'unknown')
            ->assertJsonPath('data.latest_reading', null);

        $this->patchJson('/api/v1/browser/printers/'.$printer->id, [
            'asset_number' => '3772', 'mac_address' => '00:80:77:EE:0A:0E',
            'firmware_version' => '1.19', 'owner_type' => 'customer',
            'connection_type' => 'network', 'color_capability' => 'mono',
        ])->assertOk()
            ->assertJsonPath('data.asset_number', '3772')
            ->assertJsonPath('data.owner_type', 'customer')
            ->assertJsonPath('data.mac_address', '00:80:77:EE:0A:0E');
        $this->getJson('/api/v1/browser/printers?asset=ignored&q=3772')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/browser/printers?q=192.168.0.64')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/browser/printers/'.$printer->id.'/actions')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'printer.updated')
            ->assertJsonMissingPath('data.0.before_state')
            ->assertJsonMissingPath('data.0.after_state');
        $this->assertDatabaseHas('audit_entries', [
            'tenant_id' => $tenant->id, 'entity_type' => 'Printer',
            'entity_id' => $printer->id, 'action' => 'printer.updated',
        ]);
    }

    public function test_invalid_metadata_and_unauthorized_scope_cannot_modify_another_customer(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $one = $tenant->customers()->create(['name' => 'A']);
        $two = $tenant->customers()->create(['name' => 'B']);
        $first = $tenant->printers()->create(['customer_id' => $one->id, 'manufacturer' => 'HP', 'model' => 'A']);
        $hidden = $tenant->printers()->create(['customer_id' => $two->id, 'manufacturer' => 'Ricoh', 'model' => 'B']);
        $unit = $two->locations()->create(['tenant_id' => $tenant->id, 'name' => 'Private room']);
        $manager = $this->user($tenant, 'manager');
        DB::table('tenant_user')->where('tenant_id', $tenant->id)
            ->where('user_id', $manager->id)->update(['customer_scope' => 'assigned']);
        DB::table('customer_user_access')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $manager->id,
            'customer_id' => $one->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->login($tenant, $manager);

        $this->getJson('/api/v1/browser/printers/'.$hidden->id)->assertNotFound();
        $this->getJson('/api/v1/browser/printers/'.$hidden->id.'/actions')->assertNotFound();
        $this->getJson('/api/v1/browser/printers/'.$hidden->id.'/assignments')->assertNotFound();
        $this->patchJson('/api/v1/browser/printers/'.$hidden->id, [
            'asset_number' => 'STOLEN',
        ])->assertNotFound();
        $this->deleteJson('/api/v1/browser/printers/'.$hidden->id)->assertNotFound();
        $this->postJson('/api/v1/browser/printers/'.$hidden->id.'/assignments', [
            'location_id' => $unit->id, 'reason' => 'Not authorized',
        ])->assertNotFound();
        $this->postJson('/api/v1/browser/printers', [
            'customer_id' => $two->id, 'manufacturer' => 'Unauthorized',
            'model' => 'Not allowed',
        ])->assertNotFound();

        $this->patchJson('/api/v1/browser/printers/'.$first->id, [
            'mac_address' => 'not-a-mac',
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/browser/printers/'.$first->id, [
            'owner_type' => 'unknown_vendor',
        ])->assertUnprocessable();
        $this->patchJson('/api/v1/browser/printers/'.$first->id, [
            'asset_number' => '<script>alert(1)</script>',
        ])->assertUnprocessable();
        $this->getJson('/api/v1/browser/printers/'.$first->id)
            ->assertOk()->assertJsonPath('data.asset_number', null);
        $this->assertDatabaseMissing('printers', ['id'=>$hidden->id, 'asset_number'=>'STOLEN']);
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_cross_tenant_action_history_is_never_exposed(): void
    {
        $a = Tenant::query()->create(['name' => 'A', 'slug' => 'a']);
        $b = Tenant::query()->create(['name' => 'B', 'slug' => 'b']);
        $customer = $b->customers()->create(['name' => 'Private']);
        $hidden = $b->printers()->create(['customer_id' => $customer->id, 'manufacturer' => 'Xerox', 'model' => 'Secret']);
        $this->login($a, $this->user($a, 'owner'));
        $this->getJson('/api/v1/browser/printers/'.$hidden->id)->assertNotFound();
        $this->getJson('/api/v1/browser/printers/'.$hidden->id.'/actions')->assertNotFound();
    }
}
