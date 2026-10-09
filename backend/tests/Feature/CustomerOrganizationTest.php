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

final class CustomerOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role = 'owner'): string
    {
        $user = User::query()->create([
            'name' => 'Operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'strong-test-password',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);
        return $user->createToken('test', ['api', 'tenant:'.$tenant->id])->plainTextToken;
    }

    public function test_location_and_department_lifecycle_retains_audit_and_history(): void
    {
        $tenant = Tenant::query()->create(['name'=>'Provider','slug'=>'provider']);
        $client = $tenant->customers()->create(['name'=>'Factory']);
        $token = $this->token($tenant);

        $unit = $this->withToken($token)->postJson("/api/v1/customers/{$client->id}/locations", [
            'name'=>'Matriz', 'code'=>'HQ', 'city'=>'Criciúma', 'state'=>'SC',
        ])->assertCreated()->assertJsonPath('data.name', 'Matriz')->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/customers/{$client->id}/locations/{$unit}", [
            'name'=>'Matriz nova',
        ])->assertOk()->assertJsonPath('data.name', 'Matriz nova');

        $dept = $this->withToken($token)->postJson("/api/v1/customers/{$client->id}/locations/{$unit}/departments", [
            'name'=>'Financeiro', 'code'=>'FIN', 'responsible_email'=>'financeiro@example.test',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->deleteJson("/api/v1/customers/{$client->id}/locations/{$unit}")
            ->assertUnprocessable();

        $this->withToken($token)->deleteJson("/api/v1/customers/{$client->id}/locations/{$unit}/departments/{$dept}")
            ->assertNoContent();

        $this->withToken($token)->deleteJson("/api/v1/customers/{$client->id}/locations/{$unit}")
            ->assertNoContent();

        $this->withToken($token)->getJson("/api/v1/customers/{$client->id}/locations/{$unit}")
            ->assertOk()->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('customer_departments', ['id'=>$dept, 'active'=>false]);
        $this->assertDatabaseHas('customer_locations', ['id'=>$unit, 'active'=>false]);
        $this->assertDatabaseCount('audit_entries', 5);
    }

    public function test_cost_centers_have_customer_specific_codes_and_soft_deactivation(): void
    {
        $tenant = Tenant::query()->create(['name'=>'Provider','slug'=>'provider']);
        $a = $tenant->customers()->create(['name'=>'Alpha']);
        $b = $tenant->customers()->create(['name'=>'Beta']);
        $token = $this->token($tenant);
        $urlA = "/api/v1/customers/{$a->id}/cost-centers";
        $urlB = "/api/v1/customers/{$b->id}/cost-centers";

        $id = $this->withToken($token)->postJson($urlA, ['code'=>'001','name'=>'Produção'])
            ->assertCreated()->json('data.id');

        $this->withToken($token)->postJson($urlA, ['code'=>'001','name'=>'Duplicado'])
            ->assertUnprocessable();

        $this->withToken($token)->postJson($urlB, ['code'=>'001','name'=>'Produção'])
            ->assertCreated();

        $this->withToken($token)->patchJson("{$urlA}/{$id}", ['name'=>'Produção industrial'])
            ->assertOk()->assertJsonPath('data.name','Produção industrial');

        $this->withToken($token)->deleteJson("{$urlA}/{$id}")->assertNoContent();
        $this->withToken($token)->getJson("{$urlA}/{$id}")->assertOk()->assertJsonPath('data.active', false);
    }

    public function test_cannot_access_records_from_other_customer_or_tenant(): void
    {
        $a = Tenant::query()->create(['name'=>'A','slug'=>'a']);
        $b = Tenant::query()->create(['name'=>'B','slug'=>'b']);
        $ca = $a->customers()->create(['name'=>'A']);
        $cb = $b->customers()->create(['name'=>'B']);
        $sameTenantDifferent = $a->customers()->create(['name'=>'Second']);
        $tokenA = $this->token($a);
        $tokenB = $this->token($b);
        $unit = $this->withToken($tokenA)->postJson("/api/v1/customers/{$ca->id}/locations", ['name'=>'A unit'])
            ->assertCreated()->json('data.id');
        $department = $this->withToken($tokenA)->postJson("/api/v1/customers/{$ca->id}/locations/{$unit}/departments", ['name'=>'A dept'])
            ->assertCreated()->json('data.id');
        $center = $this->withToken($tokenA)->postJson("/api/v1/customers/{$ca->id}/cost-centers", [
            'name'=>'Financeiro', 'code'=>'F',
        ])->assertCreated()->json('data.id');

        $this->withToken($tokenA)->getJson("/api/v1/customers/{$sameTenantDifferent->id}/locations/{$unit}")
            ->assertNotFound();
        $this->withToken($tokenA)->getJson("/api/v1/customers/{$sameTenantDifferent->id}/cost-centers/{$center}")
            ->assertNotFound();

        Auth::forgetGuards();
        $this->withToken($tokenB)->getJson("/api/v1/customers/{$ca->id}/locations/{$unit}")
            ->assertNotFound();
        $this->withToken($tokenB)->patchJson("/api/v1/customers/{$ca->id}/locations/{$unit}", ['name'=>'Hijack'])
            ->assertNotFound();
        $this->withToken($tokenB)->deleteJson("/api/v1/customers/{$ca->id}/locations/{$unit}/departments/{$department}")
            ->assertNotFound();
        $this->withToken($tokenB)->getJson("/api/v1/customers/{$ca->id}/cost-centers/{$center}")
            ->assertNotFound();
        $this->assertDatabaseHas('customer_locations', ['id'=>$unit,'name'=>'A unit','tenant_id'=>$a->id]);
        $this->assertDatabaseHas('customers', ['id'=>$cb->id, 'tenant_id'=>$b->id]);
    }

    public function test_inactive_parent_and_roles_cannot_create_organization(): void
    {
        $tenant = Tenant::query()->create(['name'=>'Provider','slug'=>'provider']);
        $customer = $tenant->customers()->create(['name'=>'Disabled', 'active'=>false]);
        $other = $tenant->customers()->create(['name'=>'Enabled']);
        $token = $this->token($tenant);

        $this->withToken($token)->postJson("/api/v1/customers/{$customer->id}/locations", ['name'=>'Denied'])
            ->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/customers/{$customer->id}/cost-centers", [
            'code'=>'X', 'name'=>'Denied',
        ])->assertUnprocessable();

        $unit = $this->withToken($token)->postJson("/api/v1/customers/{$other->id}/locations", ['name'=>'Enabled unit'])
            ->assertCreated()->json('data.id');
        $this->withToken($token)->deleteJson("/api/v1/customers/{$other->id}/locations/{$unit}")
            ->assertNoContent();
        $this->withToken($token)->postJson("/api/v1/customers/{$other->id}/locations/{$unit}/departments", ['name'=>'Denied'])
            ->assertUnprocessable();

        Auth::forgetGuards();
        $tech = $this->token($tenant, 'technician');
        $this->withToken($tech)->postJson("/api/v1/customers/{$other->id}/cost-centers", [
            'code'=>'X', 'name'=>'Denied',
        ])->assertForbidden();
        $this->withToken($tech)->getJson("/api/v1/customers/{$other->id}/cost-centers")
            ->assertOk();
    }

    public function test_database_rejects_cross_customer_location_assignment(): void
    {
        $tenant = Tenant::query()->create(['name'=>'A','slug'=>'a']);
        $a = $tenant->customers()->create(['name'=>'Alpha']);
        $b = $tenant->customers()->create(['name'=>'Beta']);
        $location = $a->locations()->create(['tenant_id'=>$tenant->id,'name'=>'Alpha unit']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('customer_departments')->insert([
            'tenant_id'=>$tenant->id, 'customer_id'=>$b->id, 'location_id'=>$location->id,
            'name'=>'Invalid', 'active'=>true, 'created_at'=>now(), 'updated_at'=>now(),
        ]);
    }

    public function test_dashboard_organization_counts_are_scoped_to_tenant(): void
    {
        $tenantA = Tenant::query()->create(['name'=>'A', 'slug'=>'a']);
        $tenantB = Tenant::query()->create(['name'=>'B', 'slug'=>'b']);
        $a = $tenantA->customers()->create(['name'=>'A customer']);
        $b = $tenantB->customers()->create(['name'=>'B customer']);
        $aLocation = $a->locations()->create(['tenant_id'=>$tenantA->id,'name'=>'A location']);
        $bLocation = $b->locations()->create(['tenant_id'=>$tenantB->id,'name'=>'B location']);
        $aLocation->departments()->create([
            'tenant_id'=>$tenantA->id, 'customer_id'=>$a->id, 'name'=>'Department A',
        ]);
        $bLocation->departments()->create([
            'tenant_id'=>$tenantB->id, 'customer_id'=>$b->id, 'name'=>'Department B',
        ]);
        $a->costCenters()->create(['tenant_id'=>$tenantA->id,'code'=>'A','name'=>'Center A']);
        $b->costCenters()->create(['tenant_id'=>$tenantB->id,'code'=>'B','name'=>'Center B']);

        $this->withToken($this->token($tenantA))->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('locations', 1)
            ->assertJsonPath('departments', 1)
            ->assertJsonPath('cost_centers', 1);
    }

    public function test_department_route_rejects_a_different_location_of_the_same_customer(): void
    {
        $tenant = Tenant::query()->create(['name'=>'Provider','slug'=>'provider']);
        $customer = $tenant->customers()->create(['name'=>'Factory']);
        $first = $customer->locations()->create(['tenant_id'=>$tenant->id,'name'=>'First']);
        $second = $customer->locations()->create(['tenant_id'=>$tenant->id,'name'=>'Second']);
        $department = $first->departments()->create([
            'tenant_id'=>$tenant->id, 'customer_id'=>$customer->id, 'name'=>'First only',
        ]);
        $token = $this->token($tenant);
        $this->withToken($token)->getJson("/api/v1/customers/{$customer->id}/locations/{$second->id}/departments/{$department->id}")
            ->assertNotFound();
        $this->withToken($token)->patchJson("/api/v1/customers/{$customer->id}/locations/{$second->id}/departments/{$department->id}", ['name'=>'Hijack'])
            ->assertNotFound();
        $this->assertDatabaseHas('customer_departments', ['id'=>$department->id,'name'=>'First only']);
    }
}
