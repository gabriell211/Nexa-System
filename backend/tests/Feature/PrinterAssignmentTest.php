<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PrinterAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(Tenant $tenant, string $role = 'owner'): string
    {
        $user = User::query()->create([
            'name' => 'Operator', 'email' => Str::uuid().'@example.test',
            'password' => 'test-password',
        ]);
        $tenant->users()->attach($user->id, ['role' => $role]);
        return $user->createToken('test', ['api', 'tenant:'.$tenant->id])->plainTextToken;
    }

    private function setupFleet(): array
    {
        $tenant = Tenant::query()->create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Factory']);
        $unit1 = $customer->locations()->create(['tenant_id' => $tenant->id, 'name' => 'Unit 1']);
        $unit2 = $customer->locations()->create(['tenant_id' => $tenant->id, 'name' => 'Unit 2']);
        $dept = $unit1->departments()->create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'name' => 'Finance',
        ]);
        $center = $customer->costCenters()->create([
            'tenant_id' => $tenant->id, 'code' => 'CC1', 'name' => 'Office',
        ]);
        $printer = $tenant->printers()->create([
            'customer_id' => $customer->id, 'manufacturer' => 'Kyocera', 'model' => 'MA5500',
        ]);
        return compact('tenant', 'customer', 'unit1', 'unit2', 'dept', 'center', 'printer');
    }

    public function test_assign_move_release_has_immutable_history_and_audit(): void
    {
        extract($this->setupFleet());
        $token = $this->authenticate($tenant);
        $url = "/api/v1/printers/{$printer->id}/assignments";

        $first = $this->withToken($token)->postJson($url, [
            'location_id' => $unit1->id,
            'department_id' => $dept->id,
            'cost_center_id' => $center->id,
            'reason' => 'Instalação inicial',
        ])->assertCreated()->assertJsonPath('data.location.name', 'Unit 1')->json('data.id');

        $this->withToken($token)->postJson($url, [
            'location_id' => $unit2->id,
            'reason' => 'Remanejamento autorizado',
        ])->assertCreated()->assertJsonPath('data.location.name', 'Unit 2');

        $this->withToken($token)->getJson("/api/v1/printers/{$printer->id}")
            ->assertOk()->assertJsonPath('data.current_assignment.location.name','Unit 2');

        $this->withToken($token)->getJson($url)
            ->assertOk()->assertJsonCount(2, 'data');

        $this->assertDatabaseMissing('printer_assignments', ['id' => $first, 'released_at' => null]);
        $this->assertSame(1, DB::table('printer_assignments')->where('printer_id', $printer->id)->whereNull('released_at')->count());

        $this->withToken($token)->postJson("/api/v1/printers/{$printer->id}/unassign", [
            'reason' => 'Equipamento recolhido',
        ])->assertNoContent();

        $this->assertSame(0, DB::table('printer_assignments')->where('printer_id', $printer->id)->whereNull('released_at')->count());
        $this->assertDatabaseCount('printer_assignments', 2);
        $this->assertDatabaseCount('audit_entries', 3);
    }

    public function test_rejects_invalid_customer_location_department_or_cost_center(): void
    {
        extract($this->setupFleet());
        $token = $this->authenticate($tenant);
        $foreignClient = $tenant->customers()->create(['name' => 'Another']);
        $foreignUnit = $foreignClient->locations()->create(['tenant_id'=>$tenant->id,'name'=>'Foreign']);
        $foreignCenter = $foreignClient->costCenters()->create(['tenant_id'=>$tenant->id, 'code'=>'F', 'name'=>'Foreign']);
        $url = "/api/v1/printers/{$printer->id}/assignments";

        $this->withToken($token)->postJson($url, [
            'location_id' => $foreignUnit->id, 'reason' => 'Tentativa inválida',
        ])->assertUnprocessable();

        $this->withToken($token)->postJson($url, [
            'location_id' => $unit2->id, 'department_id' => $dept->id,
            'reason' => 'Departamento errado',
        ])->assertUnprocessable();

        $this->withToken($token)->postJson($url, [
            'location_id' => $unit1->id, 'cost_center_id' => $foreignCenter->id,
            'reason' => 'Centro errado',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('printer_assignments', 0);
    }

    public function test_blocks_deactivation_while_installed_and_blocks_direct_customer_transfer(): void
    {
        extract($this->setupFleet());
        $token = $this->authenticate($tenant);
        $this->withToken($token)->postJson("/api/v1/printers/{$printer->id}/assignments", [
            'location_id' => $unit1->id, 'department_id' => $dept->id,
            'cost_center_id' => $center->id, 'reason' => 'Instalada',
        ])->assertCreated();

        $this->withToken($token)->deleteJson("/api/v1/customers/{$customer->id}/locations/{$unit1->id}")
            ->assertUnprocessable();
        $this->withToken($token)->deleteJson("/api/v1/customers/{$customer->id}/locations/{$unit1->id}/departments/{$dept->id}")
            ->assertUnprocessable();
        $this->withToken($token)->deleteJson("/api/v1/customers/{$customer->id}/cost-centers/{$center->id}")
            ->assertUnprocessable();
        $this->withToken($token)->deleteJson("/api/v1/printers/{$printer->id}")
            ->assertUnprocessable();

        $other = $tenant->customers()->create(['name'=>'Other']);
        $this->withToken($token)->patchJson("/api/v1/printers/{$printer->id}", [
            'customer_id' => $other->id,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('printers', ['id'=>$printer->id,'customer_id'=>$customer->id]);
    }

    public function test_rejects_duplicate_assignment_and_releasing_unassigned_printer(): void
    {
        extract($this->setupFleet());
        $token = $this->authenticate($tenant);
        $url = "/api/v1/printers/{$printer->id}/assignments";
        $this->withToken($token)->postJson("/api/v1/printers/{$printer->id}/unassign", [
            'reason' => 'Não instalada',
        ])->assertUnprocessable();

        $payload = ['location_id' => $unit1->id, 'reason' => 'Instalação inicial'];
        $this->withToken($token)->postJson($url, $payload)->assertCreated();
        $this->withToken($token)->postJson($url, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('printer_assignments', 1);
    }

    public function test_database_prevents_two_open_assignments_and_cross_client_reference(): void
    {
        extract($this->setupFleet());
        $token = $this->authenticate($tenant);
        $this->withToken($token)->postJson("/api/v1/printers/{$printer->id}/assignments", [
            'location_id' => $unit1->id, 'reason' => 'Instalação inicial',
        ])->assertCreated();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('printer_assignments')->insert([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'printer_id' => $printer->id, 'location_id' => $unit2->id,
            'actor_user_id' => 1, 'reason' => 'Duplicate', 'assigned_at' => now(),
            'released_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_read_only_role_cannot_move_or_release_equipment(): void
    {
        extract($this->setupFleet());
        $token = $this->authenticate($tenant, 'technician');
        $this->withToken($token)->postJson("/api/v1/printers/{$printer->id}/assignments", [
            'location_id' => $unit1->id, 'reason' => 'Tentativa',
        ])->assertForbidden();
        $this->withToken($token)->getJson("/api/v1/printers/{$printer->id}/assignments")
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
