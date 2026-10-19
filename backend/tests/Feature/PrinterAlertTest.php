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

final class PrinterAlertTest extends TestCase
{
    use RefreshDatabase;

    private function operator(Tenant $tenant,string $role): array
    {
        $user=User::query()->create([
            'name'=>$role,'email'=>(string)Str::uuid().'@example.test',
            'password'=>'test-password-long-enough',
        ]);
        $tenant->users()->attach($user->id,['role'=>$role]);
        return [$user,$user->createToken('test',['api','tenant:'.$tenant->id])->plainTextToken];
    }

    private function setupData(): array
    {
        $tenant=Tenant::query()->create(['name'=>'Alerts Provider','slug'=>'alert-provider']);
        [$owner,$token]=$this->operator($tenant,'owner');
        [$tech,$techToken]=$this->operator($tenant,'technician');
        [$warehouse,$warehouseToken]=$this->operator($tenant,'warehouse');
        $customer=$tenant->customers()->create(['name'=>'Cliente Um']);
        $otherCustomer=$tenant->customers()->create(['name'=>'Cliente Dois']);
        $printer=$tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Kyocera','model'=>'MA5500ifx',
        ]);
        $otherPrinter=$tenant->printers()->create([
            'customer_id'=>$otherCustomer->id,'manufacturer'=>'Ricoh','model'=>'4500',
        ]);
        return compact(
            'tenant','owner','token','tech','techToken','warehouse','warehouseToken',
            'customer','otherCustomer','printer','otherPrinter'
        );
    }

    private function open(string $token,int $printerId,string $code='PAPER_JAM'): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();
        return $this->withToken($token)->postJson('/api/v1/alerts',[
            'printer_id'=>$printerId,'code'=>$code,'category'=>'hardware',
            'severity'=>'critical','title'=>'Atolamento persistente',
            'description'=>'O operador confirmou falha física no local.',
            'origin'=>'collector', // Client-supplied origin must never produce fake automatic telemetry.
        ]);
    }

    private function ticket(string $token,int $customerId,int $printerId): int
    {
        Auth::forgetGuards();
        return (int)$this->withToken($token)->postJson('/api/v1/tickets',[
            'customer_id'=>$customerId,'printer_id'=>$printerId,
            'subject'=>'Atolamento recorrente','description'=>'Necessita visita no equipamento',
            'category'=>'falha','priority'=>'high',
        ])->assertCreated()->json('data.id');
    }

    public function test_manual_alert_is_tenant_scoped_persisted_and_does_not_spoof_collector(): void
    {
        extract($this->setupData());
        $id=$this->open($token,$printer->id,'paper_jam')
            ->assertCreated()
            ->assertJsonPath('data.origin','operator')
            ->assertJsonPath('data.code','PAPER_JAM')
            ->assertJsonPath('deduplicated',false)
            ->json('data.id');
        $this->withToken($token)->getJson('/api/v1/alerts')
            ->assertOk()->assertJsonPath('data.0.id',$id)
            ->assertJsonPath('data.0.customer.name','Cliente Um');
        $this->withToken($token)->getJson('/api/v1/alerts/'.$id)
            ->assertOk()->assertJsonPath('data.printer.model','MA5500ifx');
        $this->assertDatabaseHas('audit_entries',['action'=>'alert.opened']);
    }

    public function test_unresolved_manual_alert_is_deduplicated_and_resolution_allows_new_incident(): void
    {
        extract($this->setupData());
        $id=$this->open($token,$printer->id)->assertCreated()->json('data.id');
        $this->open($token,$printer->id)->assertOk()
            ->assertJsonPath('data.id',$id)->assertJsonPath('deduplicated',true);
        $this->assertDatabaseCount('printer_alerts',1);
        $this->withToken($token)->postJson("/api/v1/alerts/{$id}/transition",[
            'status'=>'acknowledged','reason'=>'Operador registrou recebimento',
        ])->assertOk()->assertJsonPath('data.status','acknowledged');
        $this->open($token,$printer->id)->assertOk()->assertJsonPath('deduplicated',true);
        $this->withToken($token)->postJson("/api/v1/alerts/{$id}/transition",[
            'status'=>'resolved','reason'=>'Atolamento removido no equipamento',
        ])->assertOk()->assertJsonPath('data.status','resolved');
        $newId=$this->open($token,$printer->id)->assertCreated()->json('data.id');
        $this->assertNotEquals($id,$newId);
        $this->assertDatabaseCount('printer_alerts',2);
    }

    public function test_an_alert_cannot_transition_without_reason_or_leave_terminal_state(): void
    {
        extract($this->setupData());
        $id=$this->open($token,$printer->id)->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("/api/v1/alerts/{$id}/transition",[
            'status'=>'resolved','reason'=>'',
        ])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/alerts/{$id}/transition",[
            'status'=>'ignored','reason'=>'Interferência sem ação necessária',
        ])->assertOk()->assertJsonPath('data.status','ignored');
        $this->withToken($token)->postJson("/api/v1/alerts/{$id}/transition",[
            'status'=>'resolved','reason'=>'Tentativa de resolver ignorado',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('audit_entries',['action'=>'alert.transitioned']);
    }

    public function test_ticket_link_requires_same_tenant_customer_printer_and_active_status(): void
    {
        extract($this->setupData());
        $id=$this->open($token,$printer->id)->assertCreated()->json('data.id');
        $wrong=$this->ticket($token,$otherCustomer->id,$otherPrinter->id);
        $this->withToken($token)->postJson("/api/v1/alerts/{$id}/link-ticket",[
            'ticket_id'=>$wrong,
        ])->assertUnprocessable();
        $correct=$this->ticket($token,$customer->id,$printer->id);
        $this->withToken($token)->postJson("/api/v1/alerts/{$id}/link-ticket",[
            'ticket_id'=>$correct,
        ])->assertOk()->assertJsonPath('data.ticket_id',$correct)
            ->assertJsonPath('data.status','linked');
        $this->withToken($token)->postJson("/api/v1/alerts/{$id}/link-ticket",[
            'ticket_id'=>$correct,
        ])->assertOk()->assertJsonPath('data.ticket_id',$correct);
        $this->assertDatabaseHas('audit_entries',['action'=>'alert.linked']);
        $this->withToken($token)->postJson("/api/v1/alerts/{$id}/transition",[
            'status'=>'resolved','reason'=>'Atendimento concluiu a revisão do alerta',
        ])->assertOk()->assertJsonPath('data.status','resolved');
    }

    public function test_resolved_ticket_cannot_be_linked_to_an_active_alert(): void
    {
        extract($this->setupData());
        $id=$this->open($token,$printer->id)->assertCreated()->json('data.id');
        $ticket=$this->ticket($token,$customer->id,$printer->id);
        // Model a historically resolved ticket: normal customer resolution
        // now requires a signed service acceptance via the field application.
        \App\Models\ServiceTicket::query()->whereKey($ticket)->update([
            'status'=>'resolved','resolved_at'=>now(),
        ]);
        $this->withToken($token)->postJson("/api/v1/alerts/{$id}/link-ticket",[
            'ticket_id'=>$ticket,
        ])->assertUnprocessable();
    }

    public function test_assigned_portfolio_filters_alert_list_and_denies_direct_urls(): void
    {
        extract($this->setupData());
        $first=$this->open($token,$printer->id)->assertCreated()->json('data.id');
        $second=$this->open($token,$otherPrinter->id)->assertCreated()->json('data.id');
        $tenant->users()->updateExistingPivot($tech->id,['customer_scope'=>'assigned']);
        DB::table('customer_user_access')->insert([
            'tenant_id'=>$tenant->id,'user_id'=>$tech->id,'customer_id'=>$customer->id,
        ]);
        Auth::forgetGuards();
        $this->withToken($techToken)->getJson('/api/v1/alerts')
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$first);
        $this->withToken($techToken)->getJson('/api/v1/alerts/'.$second)->assertNotFound();
        $this->withToken($techToken)->postJson("/api/v1/alerts/{$second}/transition",[
            'status'=>'ignored','reason'=>'Fora da carteira permitida',
        ])->assertNotFound();
        $this->open($techToken,$otherPrinter->id)->assertNotFound();
    }

    public function test_other_tenant_and_warehouse_role_cannot_access_alert_endpoints(): void
    {
        extract($this->setupData());
        $id=$this->open($token,$printer->id)->assertCreated()->json('data.id');
        $other=Tenant::query()->create(['name'=>'Foreign Provider','slug'=>'foreign-alerts']);
        [$operator,$otherToken]=$this->operator($other,'owner');
        Auth::forgetGuards();
        $this->withToken($otherToken)->getJson('/api/v1/alerts/'.$id)->assertNotFound();
        $this->open($otherToken,$printer->id)->assertNotFound();
        Auth::forgetGuards();
        $this->withToken($warehouseToken)->getJson('/api/v1/alerts')->assertForbidden();
        $this->open($warehouseToken,$printer->id)->assertForbidden();
    }

    public function test_validation_rejects_malformed_codes_and_unknown_printers(): void
    {
        extract($this->setupData());
        $this->open($token,$printer->id,'<script>')->assertUnprocessable();
        $this->open($token,987654)->assertNotFound();
        $this->assertDatabaseCount('printer_alerts',0);
    }
}
