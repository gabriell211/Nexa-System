<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\UnsignedDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PrinterProductionPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $tenant=Tenant::query()->create(['name'=>'MPS','slug'=>'mps-preview']);
        $customer=$tenant->customers()->create(['name'=>'Plant']);
        $printer=$tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Kyocera','model'=>'MA5500',
        ]);
        $user=User::query()->create([
            'name'=>'Manager','email'=>(string) Str::uuid().'@example.test',
            'password'=>'test-password',
        ]);
        $tenant->users()->attach($user->id,['role'=>'manager']);
        $token=$user->createToken('qa',['api','tenant:'.$tenant->id])->plainTextToken;

        return [$tenant,$customer,$printer,$user,$token];
    }

    private function reading(
        Tenant $tenant,int $printer,string $timestamp,
        ?string $total,?string $mono=null,?string $color=null,
    ): void {
        DB::table('printer_readings')->insert([
            'tenant_id'=>$tenant->id,'printer_id'=>$printer,
            'sample_id'=>(string) Str::uuid(),
            'collected_at'=>$timestamp,'received_at'=>'2026-10-16 12:00:00',
            'meter_total'=>$total,'meter_mono'=>$mono,'meter_color'=>$color,
        ]);
    }

    private function endpoint(int $printer,string $start='2026-10-14',string $end='2026-10-15'): string
    {
        return "/api/v1/printers/$printer/production-preview?start_date=$start&end_date=$end";
    }

    public function test_signed_safe_decimal_arithmetic_never_uses_floating_point(): void
    {
        $this->assertSame('9007199254740993',
            UnsignedDecimal::add('9007199254740992','1'));
        $this->assertSame('3',
            UnsignedDecimal::subtract('9007199254740995','9007199254740992'));
        $this->assertSame('18446744073709551616',
            UnsignedDecimal::add('18446744073709551615','1'));
        $this->assertSame(1, UnsignedDecimal::compare('9007199254740993','9007199254740992'));
        $this->assertSame('0',UnsignedDecimal::subtract('00009','9'));
        $this->expectException(\InvalidArgumentException::class);
        UnsignedDecimal::subtract('1','2');
    }

    public function test_reset_missing_and_out_of_order_samples_are_not_invented_production(): void
    {
        [$tenant,,$printer,,$token]=$this->fixture();
        // Inserted out of source order. The first observation has no baseline.
        $this->reading($tenant,$printer->id,'2026-10-14 11:00:00','9','61','2');
        $this->reading($tenant,$printer->id,'2026-10-14 09:00:00','100','50',null);
        $this->reading($tenant,$printer->id,'2026-10-15 09:00:00','12',null,'3');
        $this->reading($tenant,$printer->id,'2026-10-14 10:00:00','120','55','0');

        $this->withToken($token)->getJson($this->endpoint($printer->id))
            ->assertOk()
            ->assertJsonPath('billing_ready',false)
            ->assertJsonPath('scope','observed_pairs_only')
            ->assertJsonPath('status','review_required')
            ->assertJsonPath('sample_count',4)
            ->assertJsonPath('channels.total.observed_delta','23')
            ->assertJsonPath('channels.total.paired_intervals',2)
            ->assertJsonPath('channels.total.decrease_intervals',1)
            ->assertJsonPath('channels.mono.observed_delta','11')
            ->assertJsonPath('channels.mono.missing_intervals',1)
            ->assertJsonPath('channels.color.observed_delta','3')
            ->assertJsonPath('channels.color.missing_intervals',1);
    }

    public function test_identical_duplicates_are_collapsed_and_conflicts_break_both_adjacent_intervals(): void
    {
        [$tenant,,$printer,,$token]=$this->fixture();
        $this->reading($tenant,$printer->id,'2026-10-14 09:00:00','100');
        $this->reading($tenant,$printer->id,'2026-10-14 10:00:00','110');
        $this->reading($tenant,$printer->id,'2026-10-14 10:00:00','111');
        $this->reading($tenant,$printer->id,'2026-10-14 11:00:00','115');
        $this->reading($tenant,$printer->id,'2026-10-14 12:00:00','120');
        $this->reading($tenant,$printer->id,'2026-10-14 12:00:00','120');
        $response=$this->withToken($token)->getJson($this->endpoint($printer->id))
            ->assertOk()
            ->assertJsonPath('sample_count',6)
            ->assertJsonPath('distinct_timestamps',4)
            ->assertJsonPath('repeated_timestamps',2)
            ->assertJsonPath('conflicting_timestamps',1)
            ->assertJsonPath('status','review_required')
            ->assertJsonPath('channels.total.observed_delta','5')
            ->assertJsonPath('channels.total.paired_intervals',1);
        $this->assertArrayNotHasKey('sample_id',$response->json());
        $this->assertDatabaseCount('printer_readings',6);
    }

    public function test_exact_precision_and_null_when_no_usable_pairs_exist(): void
    {
        [$tenant,,$printer,,$token]=$this->fixture();
        $this->reading($tenant,$printer->id,'2026-10-15 08:00:00',
            '9007199254740993',null,null);
        $this->reading($tenant,$printer->id,'2026-10-15 09:00:00',
            '9007199254740995',null,null);
        $this->withToken($token)->getJson($this->endpoint($printer->id))
            ->assertOk()->assertJsonPath('status','review_required')
            ->assertJsonPath('channels.total.observed_delta','2')
            ->assertJsonPath('channels.mono.observed_delta',null)
            ->assertJsonPath('channels.color.observed_delta',null);

        $this->withToken($token)->getJson($this->endpoint($printer->id,'2026-10-14','2026-10-14'))
            ->assertOk()->assertJsonPath('status','insufficient_data')
            ->assertJsonPath('sample_count',0)
            ->assertJsonPath('channels.total.observed_delta',null)
            ->assertJsonPath('billing_ready',false);
    }

    public function test_only_pairs_inside_requested_dates_count_and_bounds_are_strict(): void
    {
        [$tenant,,$printer,,$token]=$this->fixture();
        $this->reading($tenant,$printer->id,'2026-10-13 23:59:59','100');
        $this->reading($tenant,$printer->id,'2026-10-14 00:00:00','150');
        $this->reading($tenant,$printer->id,'2026-10-15 23:59:59','170');
        $this->reading($tenant,$printer->id,'2026-10-16 00:00:00','999');
        $this->withToken($token)->getJson($this->endpoint($printer->id))
            ->assertOk()->assertJsonPath('sample_count',2)
            ->assertJsonPath('channels.total.observed_delta','20')
            ->assertJsonPath('billing_ready',false);

        $this->withToken($token)->getJson($this->endpoint($printer->id,'2026-09-15','2026-10-15'))
            ->assertOk();
        $this->withToken($token)->getJson($this->endpoint($printer->id,'2026-09-14','2026-10-15'))
            ->assertUnprocessable();
        $this->withToken($token)->getJson($this->endpoint($printer->id,'2026-10-16','2026-10-15'))
            ->assertUnprocessable();
        $this->withToken($token)->getJson($this->endpoint($printer->id,'2026-02-31','2026-10-15'))
            ->assertUnprocessable();
        $this->withToken($token)->getJson("/api/v1/printers/{$printer->id}/production-preview")
            ->assertUnprocessable();
    }

    public function test_more_than_twenty_thousand_samples_fails_without_partial_calculation(): void
    {
        [$tenant,,$printer,,$token]=$this->fixture();
        $rows=[];
        for ($i=0; $i<20001; $i++) {
            $rows[]=[
                'tenant_id'=>$tenant->id,
                'printer_id'=>$printer->id,
                'sample_id'=>sprintf('00000000-0000-4000-8000-%012d',$i+1),
                'collected_at'=>'2026-10-14 09:00:00',
                'received_at'=>'2026-10-14 09:01:00',
                'meter_total'=>100,'meter_mono'=>null,'meter_color'=>null,
            ];
            if (count($rows)===250) {
                DB::table('printer_readings')->insert($rows);
                $rows=[];
            }
        }
        if ($rows!==[]) {
            DB::table('printer_readings')->insert($rows);
        }

        $this->withToken($token)->getJson($this->endpoint($printer->id))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date']);
        $this->assertDatabaseCount('printer_readings',20001);
    }

    public function test_foreign_tenant_and_restricted_customer_cannot_read_derived_statistics(): void
    {
        [$tenant,$customer,$printer,$manager,$token]=$this->fixture();
        $otherCustomer=$tenant->customers()->create(['name'=>'Private']);
        $private=$tenant->printers()->create([
            'customer_id'=>$otherCustomer->id,'manufacturer'=>'HP','model'=>'Secure',
        ]);
        $foreignTenant=Tenant::query()->create(['name'=>'Foreign','slug'=>'foreign-preview']);
        $foreignCustomer=$foreignTenant->customers()->create(['name'=>'Foreign customer']);
        $foreignPrinter=$foreignTenant->printers()->create([
            'customer_id'=>$foreignCustomer->id,'manufacturer'=>'Epson','model'=>'Foreign',
        ]);
        $this->reading($tenant,$private->id,'2026-10-14 08:00:00','100');
        $this->reading($tenant,$private->id,'2026-10-15 08:00:00','999');
        $this->reading($foreignTenant,$foreignPrinter->id,'2026-10-14 08:00:00','10');
        $this->reading($foreignTenant,$foreignPrinter->id,'2026-10-15 08:00:00','987');

        DB::table('tenant_user')->where('tenant_id',$tenant->id)
            ->where('user_id',$manager->id)->update(['customer_scope'=>'assigned']);
        DB::table('customer_user_access')->insert([
            'tenant_id'=>$tenant->id,'user_id'=>$manager->id,
            'customer_id'=>$customer->id,
            'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
        ]);
        $this->withToken($token)->getJson($this->endpoint($printer->id))
            ->assertOk()->assertJsonPath('sample_count',0);
        $this->withToken($token)->getJson($this->endpoint($private->id))
            ->assertNotFound();
        $this->withToken($token)->getJson($this->endpoint($foreignPrinter->id))
            ->assertNotFound();
    }
}
