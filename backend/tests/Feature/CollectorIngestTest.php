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
}
