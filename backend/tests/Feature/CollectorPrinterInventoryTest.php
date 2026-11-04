<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CollectorAgent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CollectorPrinterInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_inventory_is_scoped_idempotent_and_never_overwrites_verified_printer_fields(): void
    {
        $tenant = Tenant::create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Client']);
        $printer = $tenant->printers()->create([
            'customer_id' => $customer->id,
            'manufacturer' => 'HP',
            'model' => 'Verified model',
            'serial_number' => 'ADMIN-VERIFIED',
        ]);
        $token = bin2hex(random_bytes(32));
        $collectorId = (string) Str::uuid();
        CollectorAgent::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'collector_id' => $collectorId,
            'token_hash' => hash('sha256', $token),
        ]);
        $payload = [
            'collector_id' => $collectorId,
            'printers' => [[
                'printer_id' => $printer->id,
                'ip_address' => '192.168.1.44',
                'manufacturer' => 'Device-reported HP',
                'model' => 'SNMP Model',
                'serial_number' => 'DEVICE-UNVERIFIED',
                'mac_address' => '00:11:22:33:44:55',
                'monitored' => true,
            ]],
        ];
        $this->withToken($token)->postJson('/api/v1/collector/printers/inventory', $payload)
            ->assertOk()->assertJsonPath('acknowledged.0', $printer->id);
        $this->withToken($token)->postJson('/api/v1/collector/printers/inventory', $payload)
            ->assertOk();
        $this->assertDatabaseCount('collector_printer_observations', 1);
        $this->assertDatabaseHas('collector_printer_observations', [
            'printer_id' => $printer->id,
            'model' => 'SNMP Model',
            'serial_number' => 'DEVICE-UNVERIFIED',
        ]);
        $this->assertDatabaseHas('printers', [
            'id' => $printer->id,
            'model' => 'Verified model',
            'serial_number' => 'ADMIN-VERIFIED',
        ]);
    }

    public function test_foreign_printers_are_rejected_without_partial_inventory_ingestion(): void
    {
        $tenant = Tenant::create(['name' => 'Provider', 'slug' => 'provider']);
        $one = $tenant->customers()->create(['name' => 'One']);
        $two = $tenant->customers()->create(['name' => 'Two']);
        $printer = $tenant->printers()->create([
            'customer_id' => $two->id,
            'manufacturer' => 'HP',
            'model' => 'Model',
        ]);
        $token = bin2hex(random_bytes(32));
        $collectorId = (string) Str::uuid();
        CollectorAgent::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $one->id,
            'collector_id' => $collectorId,
            'token_hash' => hash('sha256', $token),
        ]);
        $this->withToken($token)->postJson('/api/v1/collector/printers/inventory', [
            'collector_id' => $collectorId,
            'printers' => [[
                'printer_id' => $printer->id,
                'ip_address' => null,
                'manufacturer' => null,
                'model' => null,
                'serial_number' => null,
                'mac_address' => null,
                'monitored' => true,
            ]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('collector_printer_observations', 0);
    }

    public function test_inventory_requires_machine_token_and_strict_payload(): void
    {
        $this->postJson('/api/v1/collector/printers/inventory', [
            'collector_id' => (string) Str::uuid(),
            'printers' => [],
        ])->assertUnauthorized();

        $this->withToken('invalid-test-token')->postJson('/api/v1/collector/printers/inventory', [
            'collector_id' => (string) Str::uuid(),
            'printers' => [[
                'printer_id' => 1,
                'ip_address' => 'not-an-ip',
                'manufacturer' => null,
                'model' => null,
                'serial_number' => null,
                'mac_address' => 'invalid',
                'monitored' => true,
            ]],
        ])->assertUnprocessable();
    }
}
