<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PrinterLatestReadingTest extends TestCase
{
    use RefreshDatabase;

    private function token(Tenant $tenant, string $role = 'owner'): array
    {
        $user=User::query()->create([
            'name'=>'MPS operator',
            'email'=>(string) Str::uuid().'@example.test',
            'password'=>'safe-test-password',
        ]);
        $tenant->users()->attach($user->id, ['role'=>$role]);
        return [$user, $user->createToken('test', ['api','tenant:'.$tenant->id])->plainTextToken];
    }

    private function sample(
        Tenant $tenant,
        int $printerId,
        string $collectedAt,
        string $receivedAt,
        string|null $total,
        string|null $mono=null,
        string|null $color=null,
    ): void {
        DB::table('printer_readings')->insert([
            'tenant_id'=>$tenant->id,
            'printer_id'=>$printerId,
            'sample_id'=>(string) Str::uuid(),
            'meter_total'=>$total,
            'meter_mono'=>$mono,
            'meter_color'=>$color,
            'collected_at'=>$collectedAt,
            'received_at'=>$receivedAt,
        ]);
    }

    public function test_latest_reading_uses_source_time_and_last_id_on_ties_without_precision_loss(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T14:00:00Z'));
        $tenant=Tenant::query()->create(['name'=>'MPS','slug'=>'mps']);
        $customer=$tenant->customers()->create(['name'=>'Warehouse']);
        $printer=$tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Ricoh','model'=>'A',
        ]);
        $empty=$tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Kyocera','model'=>'B',
        ]);
        [, $token]=$this->token($tenant);

        // A later received_at must NOT override a newer source reading.
        $this->sample($tenant,$printer->id,'2026-10-15 09:00:00','2026-10-15 13:00:00','11');
        $this->sample($tenant,$printer->id,'2026-10-15 10:00:00','2026-10-15 11:01:00','9007199254740993',null,'0');
        $this->sample($tenant,$printer->id,'2026-10-15 10:00:00','2026-10-15 11:00:00','9007199254740995','300',null);

        $this->withToken($token)->getJson('/api/v1/printers?per_page=25')
            ->assertOk()
            ->assertJsonCount(2,'data')
            ->assertJsonPath('data.0.id',$empty->id)
            ->assertJsonPath('data.0.latest_reading',null)
            ->assertJsonPath('data.1.id',$printer->id)
            ->assertJsonPath('data.1.latest_reading.meter_total','9007199254740995')
            ->assertJsonPath('data.1.latest_reading.meter_mono','300')
            ->assertJsonPath('data.1.latest_reading.meter_color',null)
            ->assertJsonPath('data.1.latest_reading.collected_at','2026-10-15T10:00:00+00:00');

        $this->withToken($token)->getJson('/api/v1/printers/'.$printer->id)
            ->assertOk()
            ->assertJsonPath('data.latest_reading.meter_total','9007199254740995')
            ->assertJsonPath('data.latest_reading.meter_color',null)
            ->assertJsonCount(3,'data.readings');
    }

    public function test_printer_list_eager_loads_latest_counters_for_paginated_fleet(): void
    {
        $tenant=Tenant::query()->create(['name'=>'Fleet','slug'=>'fleet']);
        $customer=$tenant->customers()->create(['name'=>'School']);
        [, $token]=$this->token($tenant);
        for($i=0;$i<30;$i++){
            $printer=$tenant->printers()->create([
                'customer_id'=>$customer->id,
                'manufacturer'=>'Generic',
                'model'=>'Fleet '.$i,
            ]);
            $this->sample($tenant,$printer->id,'2026-10-15 09:00:00',
                '2026-10-15 09:01:00',(string)(100+$i));
        }
        DB::enableQueryLog();
        try {
            $response=$this->withToken($token)
                ->getJson('/api/v1/printers?per_page=30')->assertOk()
                ->assertJsonCount(30,'data');
            $this->assertSame('129',$response->json('data.0.latest_reading.meter_total'));
            $readingQueries=array_filter(DB::getQueryLog(), static fn(array $query): bool =>
                str_contains(strtolower($query['query']),'printer_readings')
            );
            $this->assertLessThanOrEqual(3,count($readingQueries),
                'Latest reading must be eagerly queried, not loaded once per printer.');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_latest_reading_stays_within_tenant_and_assigned_customer_portfolio(): void
    {
        $tenant=Tenant::query()->create(['name'=>'Scoped','slug'=>'scoped']);
        $foreign=Tenant::query()->create(['name'=>'Foreign','slug'=>'foreign']);
        $a=$tenant->customers()->create(['name'=>'Allowed']);
        $b=$tenant->customers()->create(['name'=>'Restricted']);
        $other=$foreign->customers()->create(['name'=>'Other tenant']);
        $pa=$tenant->printers()->create(['customer_id'=>$a->id,'manufacturer'=>'A','model'=>'Allowed']);
        $pb=$tenant->printers()->create(['customer_id'=>$b->id,'manufacturer'=>'B','model'=>'Restricted']);
        $pf=$foreign->printers()->create(['customer_id'=>$other->id,'manufacturer'=>'C','model'=>'Foreign']);
        $this->sample($tenant,$pa->id,'2026-10-15 09:00:00','2026-10-15 09:01:00','12');
        $this->sample($tenant,$pb->id,'2026-10-15 09:00:00','2026-10-15 09:01:00','999');
        $this->sample($foreign,$pf->id,'2026-10-15 09:00:00','2026-10-15 09:01:00','777');

        [$operator,$token]=$this->token($tenant,'manager');
        DB::table('tenant_user')->where('tenant_id',$tenant->id)
            ->where('user_id',$operator->id)
            ->update(['customer_scope'=>'assigned']);
        DB::table('customer_user_access')->insert([
            'tenant_id'=>$tenant->id,'user_id'=>$operator->id,'customer_id'=>$a->id,
            'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
        ]);
        $this->withToken($token)->getJson('/api/v1/printers')
            ->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.id',$pa->id)
            ->assertJsonPath('data.0.latest_reading.meter_total','12');
        $this->withToken($token)->getJson('/api/v1/printers/'.$pb->id)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/printers/'.$pf->id)->assertNotFound();
    }
}
