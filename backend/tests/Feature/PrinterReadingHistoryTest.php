<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PrinterReadingHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant,string $role='owner'): array
    {
        $user=User::query()->create([
            'name'=>'History operator','email'=>(string) Str::uuid().'@example.test',
            'password'=>'test-only-password',
        ]);
        $tenant->users()->attach($user->id,['role'=>$role]);
        return [$user,$user->createToken('qa',['api','tenant:'.$tenant->id])->plainTextToken];
    }

    private function reading(
        Tenant $tenant,int $printerId,string $collection,string $received,
        ?string $total,?string $mono=null,?string $color=null,?string $scan=null,
    ): int {
        return (int) DB::table('printer_readings')->insertGetId([
            'tenant_id'=>$tenant->id,'printer_id'=>$printerId,
            'sample_id'=>(string) Str::uuid(),
            'meter_total'=>$total,'meter_mono'=>$mono,'meter_color'=>$color,'meter_scan'=>$scan,
            'collected_at'=>$collection,'received_at'=>$received,
        ]);
    }

    public function test_scan_only_sample_keeps_total_null_and_exposes_scan_count_as_string(): void
    {
        $tenant=Tenant::query()->create(['name'=>'Scanner history','slug'=>'scanner-history']);
        $customer=$tenant->customers()->create(['name'=>'Office']);
        $scanner=$tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Canon','model'=>'DR-C225 II',
        ]);
        [, $token]=$this->token($tenant);
        $this->reading($tenant,$scanner->id,'2026-10-16 10:00:00',
            '2026-10-16 10:01:00',null,null,null,'169361');

        $this->withToken($token)->getJson('/api/v1/printers/'.$scanner->id.'/readings')
            ->assertOk()->assertJsonPath('total',1)
            ->assertJsonPath('data.0.meter_total',null)
            ->assertJsonPath('data.0.meter_scan','169361');
    }

    public function test_history_is_ordered_by_collection_then_id_and_retains_null_and_64_bit_values(): void
    {
        $tenant=Tenant::query()->create(['name'=>'MPS history','slug'=>'mps-history']);
        $customer=$tenant->customers()->create(['name'=>'Factory']);
        $printer=$tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Ricoh','model'=>'M',
        ]);
        [, $token]=$this->token($tenant);
        $first=$this->reading($tenant,$printer->id,'2026-10-14 11:00:00',
            '2026-10-15 13:00:00','12',null,'0');
        $second=$this->reading($tenant,$printer->id,'2026-10-15 10:00:00',
            '2026-10-15 10:05:00','9007199254740993',null,null);
        $third=$this->reading($tenant,$printer->id,'2026-10-15 10:00:00',
            '2026-10-15 10:02:00','9007199254740995','123',null);

        $this->withToken($token)->getJson('/api/v1/printers/'.$printer->id.'/readings?per_page=2')
            ->assertOk()->assertJsonPath('total',3)
            ->assertJsonCount(2,'data')
            ->assertJsonPath('data.0.id',$third)
            ->assertJsonPath('data.0.meter_total','9007199254740995')
            ->assertJsonPath('data.0.meter_mono','123')
            ->assertJsonPath('data.0.meter_color',null)
            ->assertJsonPath('data.1.id',$second)
            ->assertJsonPath('data.1.meter_total','9007199254740993');
        $this->withToken($token)->getJson('/api/v1/printers/'.$printer->id.'/readings?per_page=2&page=2')
            ->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.id',$first)
            ->assertJsonPath('data.0.meter_total','12')
            ->assertJsonPath('data.0.meter_mono',null)
            ->assertJsonPath('data.0.meter_color','0');
    }

    public function test_inclusive_calendar_dates_exclude_outside_samples_and_invalid_ranges(): void
    {
        $tenant=Tenant::query()->create(['name'=>'Dates','slug'=>'dates']);
        $customer=$tenant->customers()->create(['name'=>'Client']);
        $printer=$tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'HP','model'=>'Test',
        ]);
        [, $token]=$this->token($tenant);
        $this->reading($tenant,$printer->id,'2026-10-13 23:59:59','2026-10-15 10:00:00','10');
        $middle=$this->reading($tenant,$printer->id,'2026-10-14 00:00:00','2026-10-15 10:00:00','11');
        $last=$this->reading($tenant,$printer->id,'2026-10-15 23:59:59','2026-10-16 10:00:00','13');
        $this->reading($tenant,$printer->id,'2026-10-16 00:00:00','2026-10-16 10:00:00','14');

        $base='/api/v1/printers/'.$printer->id.'/readings';
        $this->withToken($token)->getJson($base.'?start_date=2026-10-14&end_date=2026-10-15')
            ->assertOk()->assertJsonCount(2,'data')
            ->assertJsonPath('total',2)
            ->assertJsonPath('data.0.id',$last)
            ->assertJsonPath('data.1.id',$middle);
        $this->withToken($token)->getJson($base.'?end_date=2026-10-13')
            ->assertOk()->assertJsonPath('total',1);
        $this->withToken($token)->getJson($base.'?start_date=2026-10-16')
            ->assertOk()->assertJsonPath('total',1);

        $this->withToken($token)->getJson($base.'?start_date=2026-10-16&end_date=2026-10-14')
            ->assertUnprocessable();
        $this->withToken($token)->getJson($base.'?start_date=2026-02-31')
            ->assertUnprocessable();
        $this->withToken($token)->getJson($base.'?per_page=101')
            ->assertUnprocessable();
        $this->withToken($token)->getJson($base.'?page=0')
            ->assertUnprocessable();
        $this->assertDatabaseCount('printer_readings',4);
    }

    public function test_history_enforces_tenant_and_assigned_portfolio_without_leaking_counts(): void
    {
        $tenant=Tenant::query()->create(['name'=>'Company','slug'=>'company']);
        $other=Tenant::query()->create(['name'=>'Other','slug'=>'other']);
        $allowed=$tenant->customers()->create(['name'=>'Allowed']);
        $blocked=$tenant->customers()->create(['name'=>'Blocked']);
        $external=$other->customers()->create(['name'=>'External']);
        $pa=$tenant->printers()->create(['customer_id'=>$allowed->id,'manufacturer'=>'A','model'=>'One']);
        $pb=$tenant->printers()->create(['customer_id'=>$blocked->id,'manufacturer'=>'B','model'=>'Two']);
        $pf=$other->printers()->create(['customer_id'=>$external->id,'manufacturer'=>'C','model'=>'Three']);
        $this->reading($tenant,$pa->id,'2026-10-15 09:00:00','2026-10-15 09:01:00','2');
        $this->reading($tenant,$pb->id,'2026-10-15 09:00:00','2026-10-15 09:01:00','888');
        $this->reading($other,$pf->id,'2026-10-15 09:00:00','2026-10-15 09:01:00','999');

        [$manager,$token]=$this->token($tenant,'manager');
        DB::table('tenant_user')->where('tenant_id',$tenant->id)
            ->where('user_id',$manager->id)->update(['customer_scope'=>'assigned']);
        DB::table('customer_user_access')->insert([
            'tenant_id'=>$tenant->id,'user_id'=>$manager->id,
            'customer_id'=>$allowed->id,
            'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
        ]);
        $prefix='/api/v1/printers/';
        $this->withToken($token)->getJson($prefix.$pa->id.'/readings')
            ->assertOk()->assertJsonPath('total',1)
            ->assertJsonPath('data.0.meter_total','2');
        $this->withToken($token)->getJson($prefix.$pb->id.'/readings')->assertNotFound();
        $this->withToken($token)->getJson($prefix.$pf->id.'/readings')->assertNotFound();
    }

    public function test_empty_history_returns_an_empty_page_not_invented_counters(): void
    {
        $tenant=Tenant::query()->create(['name'=>'New','slug'=>'new']);
        $customer=$tenant->customers()->create(['name'=>'New customer']);
        $printer=$tenant->printers()->create(['customer_id'=>$customer->id,'manufacturer'=>'Epson','model'=>'L']);
        [, $token]=$this->token($tenant);
        $this->withToken($token)->getJson('/api/v1/printers/'.$printer->id.'/readings')
            ->assertOk()->assertJsonPath('total',0)->assertJsonCount(0,'data');
    }
}
