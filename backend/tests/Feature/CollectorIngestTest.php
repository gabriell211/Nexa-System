<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CollectorAgent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CollectorIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_collector_acknowledges_retry_without_duplicate(): void
    {
        $tenant = Tenant::create(['name'=>'Test','slug'=>'test']);
        $customer = $tenant->customers()->create(['name'=>'Unit']);
        $printer = $tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Generic','model'=>'SNMP',
        ]);
        $token = bin2hex(random_bytes(32));
        $collectorId = (string) Str::uuid();
        CollectorAgent::create([
            'tenant_id'=>$tenant->id, 'customer_id'=>$customer->id,
            'collector_id'=>$collectorId, 'token_hash'=>hash('sha256',$token),
        ]);
        $sample = [
            'collector_id'=>$collectorId,
            'samples'=>[[
                'sample_id'=>(string) Str::uuid(),
                'printer_id'=>$printer->id,
                'collected_at'=>now()->toIso8601String(),
                'meter_total'=>133,'meter_mono'=>null,'meter_color'=>null,
            ]],
        ];
        $this->withToken($token)->postJson('/api/v1/collector/ingest', $sample)->assertOk();
        $this->withToken($token)->postJson('/api/v1/collector/ingest', $sample)->assertOk();
        $this->assertDatabaseCount('printer_readings', 1);
    }

    public function test_scan_only_meter_is_idempotent_and_conflicting_retry_is_rejected(): void
    {
        $tenant = Tenant::create(['name'=>'Scan Test','slug'=>'scan-test']);
        $customer = $tenant->customers()->create(['name'=>'Unit']);
        $scanner = $tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Canon','model'=>'DR-C225 II',
        ]);
        $token = bin2hex(random_bytes(32));
        $collectorId = (string) Str::uuid();
        CollectorAgent::create([
            'tenant_id'=>$tenant->id,'customer_id'=>$customer->id,
            'collector_id'=>$collectorId,'token_hash'=>hash('sha256',$token),
        ]);
        $body = ['collector_id'=>$collectorId,'samples'=>[[
            'sample_id'=>(string) Str::uuid(),
            'printer_id'=>$scanner->id,
            'collected_at'=>now()->toIso8601String(),
            'meter_total'=>null,'meter_mono'=>null,'meter_color'=>null,
            'meter_scan'=>169360,
        ]]];
        $this->withToken($token)->postJson('/api/v1/collector/ingest',$body)->assertOk();
        $this->withToken($token)->postJson('/api/v1/collector/ingest',$body)->assertOk();
        $this->assertDatabaseHas('printer_readings',[
            'sample_id'=>$body['samples'][0]['sample_id'],
            'meter_scan'=>169360,
            'meter_total'=>null,
        ]);
        $body['samples'][0]['meter_scan'] = 169361;
        $this->withToken($token)->postJson('/api/v1/collector/ingest',$body)->assertUnprocessable();
        $this->assertDatabaseCount('printer_readings',1);
    }

    public function test_collector_rejects_foreign_customer_printer(): void
    {
        $tenant = Tenant::create(['name'=>'Test','slug'=>'test']);
        $one = $tenant->customers()->create(['name'=>'One']);
        $two = $tenant->customers()->create(['name'=>'Two']);
        $printer = $tenant->printers()->create([
            'customer_id'=>$two->id,'manufacturer'=>'Generic','model'=>'SNMP',
        ]);
        $token = bin2hex(random_bytes(32));
        $collectorId = (string) Str::uuid();
        CollectorAgent::create([
            'tenant_id'=>$tenant->id, 'customer_id'=>$one->id,
            'collector_id'=>$collectorId, 'token_hash'=>hash('sha256',$token),
        ]);
        $this->withToken($token)->postJson('/api/v1/collector/ingest', [
            'collector_id'=>$collectorId,
            'samples'=>[[
                'sample_id'=>(string) Str::uuid(),
                'printer_id'=>$printer->id,
                'collected_at'=>now()->toIso8601String(),
                'meter_total'=>10,'meter_mono'=>null,'meter_color'=>null,
            ]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('printer_readings', 0);
    }

    public function test_revoked_collector_cannot_ingest(): void
    {
        $tenant = Tenant::create(['name'=>'Test','slug'=>'test']);
        $customer = $tenant->customers()->create(['name'=>'Unit']);
        $token = bin2hex(random_bytes(32));
        $collectorId = (string) Str::uuid();
        CollectorAgent::create([
            'tenant_id'=>$tenant->id, 'customer_id'=>$customer->id,
            'collector_id'=>$collectorId, 'token_hash'=>hash('sha256',$token),
            'active'=>false,
        ]);
        $this->withToken($token)->postJson('/api/v1/collector/ingest', [
            'collector_id'=>$collectorId,
            'samples'=>[[
                'sample_id'=>(string) Str::uuid(),
                'printer_id'=>1,
                'collected_at'=>now()->toIso8601String(),
                'meter_total'=>10,'meter_mono'=>null,'meter_color'=>null,
            ]],
        ])->assertUnauthorized();
    }

    public function test_collector_rejects_reused_sample_id_with_changed_value(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $customer = $tenant->customers()->create(['name' => 'Unit']);
        $printer = $tenant->printers()->create([
            'customer_id' => $customer->id, 'manufacturer' => 'Generic', 'model' => 'SNMP',
        ]);
        $token = bin2hex(random_bytes(32));
        $collectorId = (string) Str::uuid();
        CollectorAgent::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'collector_id' => $collectorId, 'token_hash' => hash('sha256', $token),
        ]);
        $sample = [
            'collector_id' => $collectorId,
            'samples' => [[
                'sample_id' => (string) Str::uuid(),
                'printer_id' => $printer->id,
                'collected_at' => now()->startOfSecond()->toIso8601String(),
                'meter_total' => 130, 'meter_mono' => null, 'meter_color' => null,
            ]],
        ];

        $this->withToken($token)->postJson('/api/v1/collector/ingest', $sample)->assertOk();
        $sample['samples'][0]['meter_total'] = 999;
        $this->withToken($token)->postJson('/api/v1/collector/ingest', $sample)->assertUnprocessable();

        $this->assertDatabaseCount('printer_readings', 1);
        $this->assertDatabaseHas('printer_readings', ['sample_id' => $sample['samples'][0]['sample_id'], 'meter_total' => 130]);
    }


    public function test_collector_denies_ingest_when_customer_is_inactive(): void
    {
        $tenant = Tenant::create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Blocked']);
        $printer = $tenant->printers()->create([
            'customer_id' => $customer->id, 'manufacturer' => 'Generic', 'model' => 'Printer',
        ]);
        $token = bin2hex(random_bytes(32));
        $uuid = (string) Str::uuid();
        CollectorAgent::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'collector_id' => $uuid, 'token_hash' => hash('sha256', $token),
        ]);
        $customer->update(['active' => false]);
        $this->withToken($token)->postJson('/api/v1/collector/ingest', [
            'collector_id' => $uuid,
            'samples' => [[
                'sample_id' => (string) Str::uuid(),
                'printer_id' => $printer->id,
                'collected_at' => now()->toIso8601String(),
                'meter_total' => 24, 'meter_mono' => null, 'meter_color' => null,
            ]],
        ])->assertForbidden();
        $this->assertDatabaseCount('printer_readings', 0);
    }

    public function test_collector_rejects_readings_for_inactive_printer(): void
    {
        $tenant = Tenant::create(['name' => 'Provider', 'slug' => 'provider']);
        $customer = $tenant->customers()->create(['name' => 'Active']);
        $printer = $tenant->printers()->create([
            'customer_id' => $customer->id, 'manufacturer' => 'Generic',
            'model' => 'Printer', 'active' => false,
        ]);
        $token = bin2hex(random_bytes(32));
        $uuid = (string) Str::uuid();
        CollectorAgent::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id,
            'collector_id' => $uuid, 'token_hash' => hash('sha256', $token),
        ]);
        $this->withToken($token)->postJson('/api/v1/collector/ingest', [
            'collector_id' => $uuid,
            'samples' => [[
                'sample_id' => (string) Str::uuid(),
                'printer_id' => $printer->id,
                'collected_at' => now()->toIso8601String(),
                'meter_total' => 30, 'meter_mono' => null, 'meter_color' => null,
            ]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('printer_readings', 0);
    }

    public function test_expired_reading_rejects_whole_batch_but_individual_valid_reading_remains_acceptable(): void
    {
        $tenant = Tenant::create(['name' => 'Provider', 'slug' => 'mixed-readings']);
        $customer = $tenant->customers()->create(['name' => 'Client']);
        $printer = $tenant->printers()->create([
            'customer_id' => $customer->id,
            'manufacturer' => 'Generic',
            'model' => 'Counter',
        ]);
        $token = bin2hex(random_bytes(32));
        $collectorId = (string) Str::uuid();
        CollectorAgent::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'collector_id' => $collectorId,
            'token_hash' => hash('sha256', $token),
        ]);

        $expired = [
            'sample_id' => (string) Str::uuid(),
            'printer_id' => $printer->id,
            'collected_at' => now('UTC')->subDays(15)->startOfSecond()->toIso8601String(),
            'meter_total' => 50, 'meter_mono' => null, 'meter_color' => null,
        ];
        $fresh = [
            'sample_id' => (string) Str::uuid(),
            'printer_id' => $printer->id,
            'collected_at' => now('UTC')->startOfSecond()->toIso8601String(),
            'meter_total' => 70, 'meter_mono' => null, 'meter_color' => null,
        ];

        $this->withToken($token)->postJson('/api/v1/collector/ingest', [
            'collector_id' => $collectorId,
            'samples' => [$expired, $fresh],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('printer_readings', 0);

        $this->withToken($token)->postJson('/api/v1/collector/ingest', [
            'collector_id' => $collectorId, 'samples' => [$expired],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('printer_readings', 0);

        $this->withToken($token)->postJson('/api/v1/collector/ingest', [
            'collector_id' => $collectorId, 'samples' => [$fresh],
        ])->assertOk()->assertJsonPath('acknowledged.0', $fresh['sample_id']);
        $this->assertDatabaseCount('printer_readings', 1);
        $this->assertDatabaseHas('printer_readings', [
            'sample_id' => $fresh['sample_id'],
            'meter_total' => 70,
        ]);
    }

}
