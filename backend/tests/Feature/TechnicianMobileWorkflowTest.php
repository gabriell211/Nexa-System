<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ServiceTicket;
use App\Models\ServiceTicketSignature;
use App\Models\StockItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TechnicianMobileWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function member(Tenant $tenant, string $role): User
    {
        $user=User::query()->create([
            'name'=>$role.' Tester','email'=>Str::uuid().'@example.test',
            'password'=>'strong-password-123','active'=>true,
        ]);
        $tenant->users()->attach($user->id,['role'=>$role]);
        return $user;
    }

    private function setupData(): array
    {
        $tenant=Tenant::query()->create(['name'=>'Empresa Nexa','slug'=>'nexa-mobile-tests']);
        $owner=$this->member($tenant,'owner');
        $tech=$this->member($tenant,'technician');
        $other=$this->member($tenant,'technician');
        $customer=$tenant->customers()->create(['name'=>'Cliente autorizado']);
        $printer=$tenant->printers()->create([
            'customer_id'=>$customer->id,'manufacturer'=>'Kyocera','model'=>'MA5500ifx',
        ]);
        $ticket=ServiceTicket::query()->create([
            'tenant_id'=>$tenant->id,'customer_id'=>$customer->id,'printer_id'=>$printer->id,
            'assigned_user_id'=>$tech->id,'opened_by_user_id'=>$owner->id,
            'subject'=>'Impressora travada','description'=>'A impressora deixou de imprimir.',
            'category'=>'suporte','priority'=>'high','status'=>'pending','origin'=>'backoffice',
        ]);
        $otherTicket=ServiceTicket::query()->create([
            'tenant_id'=>$tenant->id,'customer_id'=>$customer->id,'printer_id'=>$printer->id,
            'assigned_user_id'=>$other->id,'opened_by_user_id'=>$owner->id,
            'subject'=>'Outro chamado','description'=>'Atendimento atribuído a outro técnico.',
            'category'=>'suporte','priority'=>'normal','status'=>'pending','origin'=>'backoffice',
        ]);
        $item=StockItem::query()->create([
            'tenant_id'=>$tenant->id,'sku'=>'TONER-TEST','name'=>'Toner para teste',
            'category'=>'toner','unit'=>'un','active'=>true,
        ]);
        $adminToken=$owner->createToken('web-test',['api','tenant:'.$tenant->id])->plainTextToken;
        return compact('tenant','owner','tech','other','customer','printer','ticket','otherTicket','item','adminToken');
    }

    private function login(User $tech, Tenant $tenant): string
    {
        Auth::forgetGuards();
        return $this->postJson('/api/v1/mobile/auth/login',[
            'email'=>$tech->email,'password'=>'strong-password-123','tenant'=>$tenant->slug,
        ])->assertOk()->json('token');
    }

    private function png(): string
    {
        $chunk=static function (string $type, string $data): string {
            return pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
        };
        $raw=str_repeat("\x00".str_repeat("\xff\xff\xff\xff",320),180);
        $image="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',320,180,8,6,0,0,0))
            .$chunk('IDAT',gzcompress($raw)).$chunk('IEND','');
        return base64_encode($image);
    }

    public function test_mobile_ticket_list_is_exclusively_assigned_and_token_cannot_use_backoffice(): void
    {
        extract($this->setupData());
        $token=$this->login($tech,$tenant);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/mobile/tickets')
            ->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.id',$ticket->id);
        $this->withToken($token)->getJson('/api/v1/mobile/tickets/'.$otherTicket->id)->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/mobile/tickets/'.$otherTicket->id.'/start')
            ->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/tickets')->assertForbidden();
    }

    public function test_parts_are_idempotent_and_reviewed_without_changing_stock_balance(): void
    {
        extract($this->setupData());
        $token=$this->login($tech,$tenant);
        $this->withToken($token)->postJson('/api/v1/mobile/tickets/'.$ticket->id.'/start')
            ->assertOk()->assertJsonPath('data.status','in_progress');
        $payload=[
            'request_id'=>(string)Str::uuid(),'stock_item_id'=>$item->id,
            'quantity'=>2,'reason'=>'Substituição de toner danificado.',
        ];
        $id=$this->withToken($token)->postJson('/api/v1/mobile/tickets/'.$ticket->id.'/part-requests',$payload)
            ->assertCreated()->assertJsonPath('data.status','pending')->json('data.id');
        $this->withToken($token)->postJson('/api/v1/mobile/tickets/'.$ticket->id.'/part-requests',$payload)
            ->assertOk()->assertJsonPath('data.id',$id);
        $this->assertDatabaseCount('service_ticket_part_requests',1);
        Auth::forgetGuards();
        $this->withToken($adminToken)->getJson('/api/v1/tickets/part-requests?status=pending')
            ->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.id',$id);
        $this->withToken($adminToken)->postJson('/api/v1/tickets/part-requests/'.$id.'/review',[
            'status'=>'approved','review_note'=>'Peça autorizada para o chamado.',
        ])->assertOk()->assertJsonPath('data.status','approved');
        $this->withToken($adminToken)->postJson('/api/v1/tickets/part-requests/'.$id.'/review',[
            'status'=>'approved','review_note'=>'Não deve aprovar duas vezes.',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_signature_is_required_to_resolve_from_mobile_and_is_private(): void
    {
        Storage::fake('local');
        extract($this->setupData());
        $token=$this->login($tech,$tenant);
        $payload=[
            'signer_name'=>'Carlos da Silva','service_summary'=>'Equipamento reparado e testado.',
            'signature_png_base64'=>$this->png(),'consent'=>true,
        ];
        $this->withToken($token)->postJson('/api/v1/mobile/tickets/'.$ticket->id.'/signature',$payload)
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/mobile/tickets/'.$ticket->id.'/start')
            ->assertOk();
        $this->withToken($token)->postJson('/api/v1/mobile/tickets/'.$ticket->id.'/signature',[
            ...$payload,'signature_png_base64'=>'não é uma assinatura',
        ])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/mobile/tickets/'.$ticket->id.'/signature',$payload)
            ->assertOk()->assertJsonPath('data.status','resolved')
            ->assertJsonPath('data.signature.signer_name','Carlos da Silva');
        $record=ServiceTicketSignature::query()->firstOrFail();
        $this->assertSame(hash('sha256',base64_decode($payload['signature_png_base64'])), $record->sha256);
        Storage::disk('local')->assertExists($record->storage_path);
        $this->withToken($token)->postJson('/api/v1/mobile/tickets/'.$ticket->id.'/signature',$payload)
            ->assertUnprocessable();
        $this->assertDatabaseCount('service_ticket_signatures',1);
        Auth::forgetGuards();
        $this->withToken($adminToken)->get('/api/v1/tickets/'.$ticket->id.'/signature/image')
            ->assertOk()->assertHeader('Content-Type','image/png');
        $this->assertDatabaseHas('audit_entries',['action'=>'ticket.customer.signed']);
    }

    public function test_technician_login_rejects_other_roles_and_part_from_another_tenant(): void
    {
        extract($this->setupData());
        $this->postJson('/api/v1/mobile/auth/login',[
            'email'=>$owner->email,'password'=>'strong-password-123','tenant'=>$tenant->slug,
        ])->assertUnprocessable();
        $otherTenant=Tenant::query()->create(['name'=>'Outra Empresa','slug'=>'other-mobile']);
        $foreign=StockItem::query()->create([
            'tenant_id'=>$otherTenant->id,'sku'=>'FOREIGN','name'=>'Peça estrangeira',
            'category'=>'toner','unit'=>'un','active'=>true,
        ]);
        $token=$this->login($tech,$tenant);
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/v1/mobile/tickets/'.$ticket->id.'/part-requests',[
            'request_id'=>(string)Str::uuid(),'stock_item_id'=>$foreign->id,
            'quantity'=>1,'reason'=>'Tentativa cruzando empresas.',
        ])->assertNotFound();
        $this->assertDatabaseCount('service_ticket_part_requests',0);
    }

    public function test_customer_request_dispatch_mobile_acceptance_and_administrative_closure_end_to_end(): void
    {
        Storage::fake('local');
        extract($this->setupData());
        $client=$this->member($tenant,'customer');
        $tenant->users()->updateExistingPivot($client->id,['customer_scope'=>'assigned']);
        \Illuminate\Support\Facades\DB::table('customer_user_access')->insert([
            'tenant_id'=>$tenant->id,'user_id'=>$client->id,'customer_id'=>$customer->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $ticket->update([
            'status'=>'triage','assigned_user_id'=>null,
            'opened_by_user_id'=>$client->id,'origin'=>'customer_portal',
        ]);

        $base='/api/v1/tickets/'.$ticket->id;
        $assign=[
            'assigned_user_id'=>$tech->id,
            'reason'=>'Visita técnica atribuída pelo administrativo.',
        ];
        $this->withToken($adminToken)->postJson($base.'/assign',[
            ...$assign,'assigned_user_id'=>$owner->id,
        ])->assertUnprocessable();
        $this->withToken($adminToken)->postJson($base.'/transition',[
            'status'=>'resolved','reason'=>'Sem aceite do cliente ainda.',
        ])->assertUnprocessable();
        $this->withToken($adminToken)->postJson($base.'/assign',$assign)
            ->assertOk()->assertJsonPath('data.status','pending')
            ->assertJsonPath('data.assignee.id',$tech->id);
        $this->assertDatabaseHas('audit_entries',['action'=>'ticket.assigned']);
        $this->withToken($adminToken)->postJson($base.'/assign',$assign)
            ->assertOk()->assertJsonPath('data.status','pending');
        $this->assertSame(1, \App\Models\AuditEntry::query()
            ->where('action','ticket.assigned')->count());

        $mobileToken=$this->login($tech,$tenant);
        $mobile='/api/v1/mobile/tickets/'.$ticket->id;
        Auth::forgetGuards();
        $this->withToken($mobileToken)->getJson('/api/v1/mobile/tickets')
            ->assertOk()->assertJsonCount(1,'data')
            ->assertJsonPath('data.0.id',$ticket->id);
        $this->withToken($mobileToken)->postJson($base.'/assign',$assign)->assertForbidden();
        $this->withToken($mobileToken)->postJson($mobile.'/start')
            ->assertOk()->assertJsonPath('data.status','in_progress');
        $this->withToken($mobileToken)->postJson($mobile.'/signature',[
            'signer_name'=>'Responsável pelo equipamento',
            'service_summary'=>'Impressora testada e reparada em atendimento presencial.',
            'signature_png_base64'=>$this->png(),
            'consent'=>true,
        ])->assertOk()->assertJsonPath('data.status','resolved');

        Auth::forgetGuards();
        $this->withToken($mobileToken)->postJson($base.'/transition',[
            'status'=>'closed','reason'=>'Tentativa de encerramento pelo aplicativo.',
        ])->assertForbidden();
        Auth::forgetGuards();
        $this->withToken($adminToken)->postJson($base.'/transition',[
            'status'=>'closed','reason'=>'Aceite conferido e chamado encerrado.',
        ])->assertOk()->assertJsonPath('data.status','closed')
            ->assertJsonPath('data.signature.signer_name','Responsável pelo equipamento');
        $this->withToken($adminToken)->postJson($base.'/transition',[
            'status'=>'triage','reason'=>'Não reutilizar assinatura de atendimento antigo.',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('audit_entries',['action'=>'ticket.customer.signed']);
        $this->assertDatabaseHas('audit_entries',['action'=>'ticket.transitioned']);
        $this->assertDatabaseHas('service_tickets',['id'=>$ticket->id,'status'=>'closed']);
        $this->assertDatabaseCount('service_ticket_signatures',1);

        Auth::forgetGuards();
        $this->postJson('/api/v1/browser/auth/login',[
            'email'=>$client->email,'password'=>'strong-password-123',
            'tenant'=>$tenant->slug,
        ])->assertOk();
        $this->getJson('/api/v1/browser/client/tickets/'.$ticket->id)
            ->assertOk()->assertJsonPath('data.status','closed')
            ->assertJsonPath('data.signature.signer_name','Responsável pelo equipamento');

        Auth::forgetGuards();
        $this->withToken($mobileToken)->getJson('/api/v1/mobile/tickets')
            ->assertOk()->assertJsonCount(0,'data');
    }
}
