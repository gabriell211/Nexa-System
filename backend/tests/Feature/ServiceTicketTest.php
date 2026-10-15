<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ServiceTicketTest extends TestCase
{
    use RefreshDatabase;

    private function member(Tenant $tenant,string $role): array
    {
        $user=User::query()->create([
            'name'=>$role.' operator','email'=>Str::uuid().'@example.test',
            'password'=>'long-secure-test-password',
        ]);
        $tenant->users()->attach($user->id,['role'=>$role]);
        $token=$user->createToken('test',['api','tenant:'.$tenant->id])->plainTextToken;
        return [$user,$token];
    }
    private function fixture(): array
    {
        $tenant=Tenant::query()->create(['name'=>'Provider','slug'=>'tickets-provider']);
        [$owner,$token]=$this->member($tenant,'owner');
        [$tech,$techToken]=$this->member($tenant,'technician');
        $customer=$tenant->customers()->create(['name'=>'Cliente autorizado']);
        $foreignCustomer=$tenant->customers()->create(['name'=>'Outro cliente']);
        $printer=$tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Kyocera','model'=>'MA5500ifx',
        ]);
        return compact('tenant','owner','token','tech','techToken','customer','foreignCustomer','printer');
    }
    private function create(string $token,?int $customerId,?int $printerId,?int $assignee=null): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();
        return $this->withToken($token)->postJson('/api/v1/tickets',[
            'customer_id'=>$customerId,'printer_id'=>$printerId,
            'assigned_user_id'=>$assignee,'subject'=>'Máquina sem impressão',
            'description'=>'A impressão está saindo totalmente em branco.',
            'category'=>'falha_impressao','priority'=>'high',
        ]);
    }

    public function test_create_list_and_retrieve_with_related_assets(): void
    {
        extract($this->fixture());
        $id=$this->create($token,$customer->id,$printer->id,$tech->id)
            ->assertCreated()->assertJsonPath('data.status','triage')
            ->assertJsonPath('data.customer.name','Cliente autorizado')
            ->assertJsonPath('data.assignee.id',$tech->id)->json('data.id');
        $this->withToken($token)->getJson('/api/v1/tickets')
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$id);
        $this->withToken($token)->getJson('/api/v1/tickets/'.$id)
            ->assertOk()->assertJsonPath('data.printer.model','MA5500ifx');
        $this->assertDatabaseHas('audit_entries',['action'=>'ticket.created']);
    }

    public function test_internal_ticket_requires_full_portfolio_and_cannot_reference_printer(): void
    {
        extract($this->fixture());
        $this->create($token,null,null)->assertCreated();
        $this->create($token,null,$printer->id)->assertUnprocessable();
        $this->assertDatabaseCount('service_tickets',1);
    }

    public function test_list_filters_by_printer_before_pagination_and_validates_the_id(): void
    {
        extract($this->fixture());
        $otherPrinter=$tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Ricoh','model'=>'Other',
        ]);
        $wanted=$this->create($token,$customer->id,$printer->id)->assertCreated()->json('data.id');
        $this->create($token,$customer->id,$otherPrinter->id)->assertCreated();

        $this->withToken($token)->getJson('/api/v1/tickets?printer_id='.$printer->id.'&per_page=1')
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('meta.total',1)
            ->assertJsonPath('data.0.id',$wanted);
        $this->withToken($token)->getJson('/api/v1/tickets?printer_id=invalid')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/tickets?printer_id=0')->assertUnprocessable();
    }

    public function test_list_filters_by_customer_and_open_state_before_pagination(): void
    {
        extract($this->fixture());
        $wanted=$this->create($token,$customer->id,$printer->id)->assertCreated()->json('data.id');
        $closed=$this->create($token,$customer->id,$printer->id)->assertCreated()->json('data.id');
        $other=$this->create($token,$foreignCustomer->id,null)->assertCreated()->json('data.id');
        \App\Models\ServiceTicket::query()->whereKey($closed)->update(['status'=>'closed']);

        $this->withToken($token)->getJson('/api/v1/tickets?customer_id='.$customer->id.'&open_only=1&per_page=1')
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('meta.total',1)
            ->assertJsonPath('data.0.id',$wanted);
        $this->withToken($token)->getJson('/api/v1/tickets?customer_id='.$foreignCustomer->id.'&open_only=true')
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$other);
        $this->withToken($token)->getJson('/api/v1/tickets?customer_id=invalid')->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/tickets?open_only=maybe')->assertUnprocessable();
    }

    public function test_transitions_require_assignee_and_prevent_close_before_resolution(): void
    {
        extract($this->fixture());
        $id=$this->create($token,$customer->id,$printer->id)->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("/api/v1/tickets/{$id}/transition",[
            'status'=>'in_progress','reason'=>'Atendimento iniciado por técnico',
        ])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/tickets/{$id}/transition",[
            'status'=>'closed','reason'=>'Atendimento não finalizado',
        ])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/tickets/{$id}/transition",[
            'status'=>'in_progress','assigned_user_id'=>$tech->id,
            'reason'=>'Designado técnico autorizado',
        ])->assertOk()->assertJsonPath('data.status','in_progress');
        $this->withToken($token)->postJson("/api/v1/tickets/{$id}/transition",[
            'status'=>'resolved','reason'=>'Problema foi solucionado',
        ])->assertOk()->assertJsonPath('data.status','resolved');
        $this->withToken($token)->postJson("/api/v1/tickets/{$id}/transition",[
            'status'=>'closed','reason'=>'Confirmação do atendimento',
        ])->assertOk()->assertJsonPath('data.status','closed');
        $this->withToken($token)->postJson("/api/v1/tickets/{$id}/comments",[
            'body'=>'Comentário tardio','internal'=>true,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('audit_entries',['action'=>'ticket.transitioned']);
    }

    public function test_comment_visibility_is_explicit_and_internal_message_remains_only_for_staff(): void
    {
        extract($this->fixture());
        $id=$this->create($token,$customer->id,$printer->id)->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("/api/v1/tickets/{$id}/comments",[
            'body'=>'Nota técnica não pública','internal'=>true,
        ])->assertCreated()->assertJsonPath('data.internal',true);
        $this->withToken($token)->postJson("/api/v1/tickets/{$id}/comments",[
            'body'=>'Equipamento em análise','internal'=>false,
        ])->assertCreated()->assertJsonPath('data.internal',false);
        $this->withToken($token)->getJson("/api/v1/tickets/{$id}")
            ->assertOk()->assertJsonCount(2,'data.comments')
            ->assertJsonPath('data.comments.0.internal',true);
        $audit = \App\Models\AuditEntry::query()
            ->where('action','ticket.comment.created')->get();
        $this->assertCount(2, $audit);
        foreach ($audit as $entry) {
            $this->assertStringNotContainsString(
                'Nota técnica não pública', json_encode($entry->after_state)
            );
        }
    }

    public function test_restricted_portfolio_filters_lists_and_blocks_objects_and_internal_work(): void
    {
        extract($this->fixture());
        $first=$this->create($token,$customer->id,$printer->id)->assertCreated()->json('data.id');
        $second=$this->create($token,$foreignCustomer->id,null)->assertCreated()->json('data.id');
        $internal=$this->create($token,null,null)->assertCreated()->json('data.id');
        $tenant->users()->updateExistingPivot($tech->id,['customer_scope'=>'assigned']);
        \Illuminate\Support\Facades\DB::table('customer_user_access')->insert([
            'tenant_id'=>$tenant->id,'customer_id'=>$customer->id,'user_id'=>$tech->id,
        ]);
        Auth::forgetGuards();
        $this->withToken($techToken)->getJson('/api/v1/tickets')
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$first);
        $this->withToken($techToken)->getJson('/api/v1/tickets?printer_id='.$printer->id)
            ->assertOk()->assertJsonCount(1,'data')->assertJsonPath('data.0.id',$first);
        $this->withToken($techToken)->getJson('/api/v1/tickets?customer_id='.$foreignCustomer->id.'&open_only=1')
            ->assertOk()->assertJsonCount(0,'data');
        $this->withToken($techToken)->getJson('/api/v1/tickets/'.$second)->assertNotFound();
        $this->withToken($techToken)->getJson('/api/v1/tickets/'.$internal)->assertForbidden();
        $this->withToken($techToken)->postJson("/api/v1/tickets/{$second}/comments",[
            'body'=>'Não permitido','internal'=>true,
        ])->assertNotFound();
        $this->create($techToken,null,null)->assertForbidden();
        $this->create($techToken,$foreignCustomer->id,null)->assertNotFound();
    }

    public function test_cross_tenant_assignment_and_printer_are_rejected(): void
    {
        extract($this->fixture());
        $other=Tenant::query()->create(['name'=>'Other provider','slug'=>'other-tickets']);
        [$outsider,$outsiderToken]=$this->member($other,'technician');
        $this->create($token,$customer->id,$printer->id,$outsider->id)->assertUnprocessable();
        $foreignPrinter=$tenant->printers()->create([
            'customer_id'=>$foreignCustomer->id,'manufacturer'=>'Ricoh','model'=>'4500',
        ]);
        $this->create($token,$customer->id,$foreignPrinter->id)->assertUnprocessable();
        $id=$this->create($token,$customer->id,$printer->id)->assertCreated()->json('data.id');
        Auth::forgetGuards();
        $this->withToken($outsiderToken)->getJson('/api/v1/tickets/'.$id)->assertNotFound();
        $this->assertDatabaseCount('service_tickets',1);
    }

    public function test_technician_cannot_reopen_closed_ticket_but_supervisor_can(): void
    {
        extract($this->fixture());
        $id=$this->create($token,$customer->id,$printer->id,$tech->id)->assertCreated()->json('data.id');
        foreach (['in_progress','resolved','closed'] as $status) {
            $this->withToken($token)->postJson("/api/v1/tickets/{$id}/transition",[
                'status'=>$status,'reason'=>'Passagem legítima de estado',
            ])->assertOk();
        }
        Auth::forgetGuards();
        $this->withToken($techToken)->postJson("/api/v1/tickets/{$id}/transition",[
            'status'=>'triage','reason'=>'Reabrir sem permissão',
        ])->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($token)->postJson("/api/v1/tickets/{$id}/transition",[
            'status'=>'triage','reason'=>'Reabertura autorizada',
        ])->assertOk()->assertJsonPath('data.closed_at',null);
    }
}
