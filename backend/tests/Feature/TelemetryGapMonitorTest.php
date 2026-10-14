<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Printer;
use App\Models\PrinterAlert;
use App\Models\Tenant;
use App\Services\TelemetryGapMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TelemetryGapMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function printer(string $slug): Printer
    {
        $tenant=Tenant::query()->create(['name'=>'Provider '.$slug,'slug'=>$slug]);
        $customer=$tenant->customers()->create(['name'=>'Customer '.$slug]);
        return $tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Kyocera','model'=>'Lab Model',
        ]);
    }

    private function sample(Printer $printer,CarbonImmutable $collectedAt): int
    {
        return (int) DB::table('printer_readings')->insertGetId([
            'tenant_id'=>$printer->tenant_id,'printer_id'=>$printer->id,
            'sample_id'=>(string) Str::uuid(),'meter_total'=>125,
            'meter_mono'=>null,'meter_color'=>null,
            'collected_at'=>$collectedAt,'received_at'=>now('UTC'),
        ]);
    }

    private function runMonitor(): array
    {
        return app(TelemetryGapMonitor::class)->check(60,10);
    }

    public function test_no_reading_does_not_claim_a_printer_is_offline(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $this->printer('zero-reading');
        $this->assertSame(['checked'=>0,'opened'=>0,'resolved'=>0,'deduplicated'=>0],
            $this->runMonitor());
        $this->assertDatabaseCount('printer_alerts',0);
    }

    public function test_silence_threshold_waits_for_an_observed_sample(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $printer=$this->printer('threshold');
        $this->sample($printer,CarbonImmutable::parse('2026-10-14T11:01:00Z'));
        $this->assertSame(0,$this->runMonitor()['opened']);
        $this->travel(2)->minutes();
        $this->assertSame(1,$this->runMonitor()['opened']);
        $this->assertDatabaseHas('printer_alerts',[
            'printer_id'=>$printer->id,'origin'=>'telemetry','status'=>'active',
            'code'=>'TELEMETRY_GAP','created_by_user_id'=>null,
        ]);
    }

    public function test_retry_is_deduplicated_even_if_operator_ignored_the_incident(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $printer=$this->printer('dedup');
        $id=$this->sample($printer,CarbonImmutable::parse('2026-10-14T09:00:00Z'));
        $this->assertSame(1,$this->runMonitor()['opened']);
        $this->assertSame(0,$this->runMonitor()['opened']);
        $alert=PrinterAlert::query()->firstOrFail();
        $this->assertSame($id,(int)$alert->telemetry_anchor_id);
        $alert->update(['status'=>'ignored','ignored_at'=>now('UTC')]);
        $this->assertSame(0,$this->runMonitor()['opened']);
        $this->assertDatabaseCount('printer_alerts',1);
        $this->assertDatabaseHas('audit_entries',[
            'actor_user_id'=>null,'origin'=>'scheduled-telemetry-check',
            'action'=>'alert.telemetry_gap.opened',
        ]);
    }

    public function test_auto_recovery_needs_two_new_samples_separated_by_stability_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $printer=$this->printer('recovery');
        $this->sample($printer,CarbonImmutable::parse('2026-10-14T09:00:00Z'));
        $this->assertSame(1,$this->runMonitor()['opened']);
        $this->travel(1)->minutes();
        $this->sample($printer,CarbonImmutable::now('UTC'));
        $this->assertSame(0,$this->runMonitor()['resolved']);
        $this->travel(5)->minutes();
        $this->sample($printer,CarbonImmutable::now('UTC'));
        $this->assertSame(0,$this->runMonitor()['resolved']);
        $this->travel(6)->minutes();
        $this->sample($printer,CarbonImmutable::now('UTC'));
        $this->assertSame(1,$this->runMonitor()['resolved']);
        $this->assertDatabaseHas('printer_alerts',[
            'printer_id'=>$printer->id,'origin'=>'telemetry','status'=>'resolved',
        ]);
        $this->assertDatabaseHas('audit_entries',[
            'actor_user_id'=>null,'action'=>'alert.telemetry_gap.recovered',
        ]);
    }

    public function test_a_new_gap_after_recovery_creates_new_incident_with_distinct_anchor(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $printer=$this->printer('new-episode');
        $this->sample($printer,CarbonImmutable::parse('2026-10-14T09:00:00Z'));
        $this->runMonitor();
        $this->travel(2)->minutes();
        $this->sample($printer,CarbonImmutable::now('UTC'));
        $this->travel(12)->minutes();
        $this->sample($printer,CarbonImmutable::now('UTC'));
        $this->assertSame(1,$this->runMonitor()['resolved']);
        $this->travel(61)->minutes();
        $this->assertSame(1,$this->runMonitor()['opened']);
        $this->assertDatabaseCount('printer_alerts',2);
        $ids=PrinterAlert::query()->pluck('telemetry_anchor_id')->unique();
        $this->assertCount(2,$ids);
    }

    public function test_inactive_printers_customers_and_tenants_are_excluded(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $p=$this->printer('inactive-printer');
        $c=$this->printer('inactive-customer');
        $t=$this->printer('inactive-tenant');
        foreach ([$p,$c,$t] as $printer) {
            $this->sample($printer,CarbonImmutable::parse('2026-10-14T09:00:00Z'));
        }
        $p->update(['active'=>false]);
        $c->customer()->update(['active'=>false]);
        $t->tenant()->update(['active'=>false]);
        $this->assertSame(0,$this->runMonitor()['opened']);
        $this->assertDatabaseCount('printer_alerts',0);
    }

    public function test_expired_reading_does_not_recover_without_fresh_collection_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $printer=$this->printer('late-delivery');
        $this->sample($printer,CarbonImmutable::parse('2026-10-14T09:00:00Z'));
        $this->runMonitor();
        $this->travel(2)->minutes();
        // Backlog delivered now, but collected before the incident: not recovery.
        $this->sample($printer,CarbonImmutable::parse('2026-10-14T10:00:00Z'));
        $this->sample($printer,CarbonImmutable::parse('2026-10-14T10:20:00Z'));
        $this->assertSame(0,$this->runMonitor()['resolved']);
        $this->assertDatabaseHas('printer_alerts',['printer_id'=>$printer->id,'status'=>'active']);
    }

    public function test_linked_telemetry_incident_is_never_auto_closed_by_the_monitor(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $printer=$this->printer('linked');
        $this->sample($printer,CarbonImmutable::parse('2026-10-14T09:00:00Z'));
        $this->runMonitor();
        PrinterAlert::query()->firstOrFail()->update(['status'=>'linked']);
        $this->travel(2)->minutes();
        $this->sample($printer,CarbonImmutable::now('UTC'));
        $this->travel(12)->minutes();
        $this->sample($printer,CarbonImmutable::now('UTC'));
        $this->assertSame(0,$this->runMonitor()['resolved']);
        $this->assertDatabaseHas('printer_alerts',['printer_id'=>$printer->id,'status'=>'linked']);
    }

    public function test_disabled_command_and_invalid_thresholds_fail_closed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        $printer=$this->printer('disabled');
        $this->sample($printer,CarbonImmutable::parse('2026-10-14T09:00:00Z'));
        config()->set('nexa.telemetry_gap.enabled',false);
        $this->artisan('nexa:alerts:check-telemetry')->assertSuccessful();
        $this->assertDatabaseCount('printer_alerts',0);
        config()->set('nexa.telemetry_gap.enabled',true);
        config()->set('nexa.telemetry_gap.threshold_minutes',0);
        $this->artisan('nexa:alerts:check-telemetry')->assertFailed();
        $this->assertDatabaseCount('printer_alerts',0);
        config()->set('nexa.telemetry_gap.threshold_minutes',60);
        $this->artisan('nexa:alerts:check-telemetry')->assertSuccessful();
        $this->assertDatabaseCount('printer_alerts',1);
    }
}
