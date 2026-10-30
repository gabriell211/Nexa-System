<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\ServiceTicket;
use App\Models\ServiceTicketComment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServiceTicketService
{
    private const TRANSITIONS = [
        'triage'=>['unassigned','pending','in_progress','resolved'],
        'unassigned'=>['pending','in_progress'],
        'pending'=>['in_progress','resolved'],
        'in_progress'=>['pending','resolved'],
        'resolved'=>['closed','in_progress'],
        'closed'=>['triage'],
    ];

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function create(Request $request, array $data): ServiceTicket
    {
        $tenant=$request->attributes->get('nexa_tenant');
        $actor=$request->user();
        if ($request->attributes->get('nexa_role') === 'technician'
            && isset($data['assigned_user_id'])) {
            abort(403,'A atribuição de responsáveis é exclusiva da equipe administrativa.');
        }
        return DB::transaction(function () use ($request,$tenant,$actor,$data): ServiceTicket {
            $this->validateLinks($request,$tenant,$data);
            $ticket=ServiceTicket::query()->create([
                'tenant_id'=>$tenant->id,'customer_id'=>$data['customer_id'],
                'printer_id'=>$data['printer_id'],
                'assigned_user_id'=>$data['assigned_user_id']??null,
                'opened_by_user_id'=>$actor->id,'subject'=>$data['subject'],
                'description'=>$data['description'],'category'=>$data['category'],
                'priority'=>$data['priority'],
                'status'=>isset($data['assigned_user_id']) ? 'pending' : 'triage',
                'origin'=>'backoffice',
            ]);
            $this->audit->write($tenant,$actor,'ticket.created',$ticket,null,
                $ticket->only(['customer_id','printer_id','assigned_user_id','subject','priority','status']));
            return $this->detail($ticket);
        });
    }

    /**
     * Dispatch is deliberately independent of lifecycle transitions: the
     * administrator can assign an incoming customer ticket without starting it.
     */
    public function assign(Request $request, int $id, array $data): ServiceTicket
    {
        if (!in_array($request->attributes->get('nexa_role'), ['owner','admin','manager','supervisor'], true)) {
            abort(403, 'Somente a coordenação pode atribuir chamados.');
        }
        $tenant = $request->attributes->get('nexa_tenant');
        $actor = $request->user();
        return DB::transaction(function () use ($request, $tenant, $actor, $id, $data): ServiceTicket {
            $ticket = ServiceTicket::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($id);
            $this->assertVisible($request, $ticket);
            if (!in_array($ticket->status, ['triage', 'unassigned', 'pending'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Reatribuição de atendimento iniciado ou finalizado exige novo fluxo de triagem.',
                ]);
            }
            $technicianId = (int) $data['assigned_user_id'];
            $this->validateAssignee($tenant, $technicianId);
            if ((int) $ticket->assigned_user_id === $technicianId && $ticket->status === 'pending') {
                return $this->detail($ticket);
            }
            $before = $ticket->only(['status', 'assigned_user_id']);
            $ticket->assigned_user_id = $technicianId;
            $ticket->status = 'pending';
            $ticket->save();
            $this->audit->write($tenant, $actor, 'ticket.assigned', $ticket, $before, [
                'status' => $ticket->status,
                'assigned_user_id' => $ticket->assigned_user_id,
                'reason' => $data['reason'],
            ]);
            return $this->detail($ticket);
        }, 3);
    }

    public function transition(Request $request,int $id,array $data): ServiceTicket
    {
        $tenant=$request->attributes->get('nexa_tenant');
        $actor=$request->user();
        return DB::transaction(function () use ($request,$tenant,$actor,$id,$data): ServiceTicket {
            $ticket=ServiceTicket::query()->where('tenant_id',$tenant->id)
                ->lockForUpdate()->findOrFail($id);
            $this->assertVisible($request,$ticket);
            $old=$ticket->status;
            $new=$data['status'];
            if ($request->attributes->get('nexa_role') === 'technician') {
                if ((int)$ticket->assigned_user_id !== (int)$actor->id) abort(404);
                if (array_key_exists('assigned_user_id',$data)) {
                    abort(403,'Apenas a administração pode trocar o responsável.');
                }
                if ($new === 'resolved' && !$ticket->signature()->exists()) {
                    throw ValidationException::withMessages([
                        'signature'=>'A assinatura eletrônica do cliente é obrigatória para conclusão pelo técnico.',
                    ]);
                }
            }
            if (!in_array($new,self::TRANSITIONS[$old]??[],true)) {
                throw ValidationException::withMessages([
                    'status'=>'Transição de estado não permitida.',
                ]);
            }
            if ($new === 'closed' && !in_array($request->attributes->get('nexa_role'),
                ['owner','admin','manager','supervisor'], true)) {
                abort(403, 'O encerramento exige validação administrativa.');
            }
            if ($ticket->customer_id !== null && in_array($new, ['resolved','closed'], true)
                && !$ticket->signature()->exists()) {
                throw ValidationException::withMessages([
                    'signature' => 'O cliente precisa assinar o atendimento no aplicativo técnico antes da conclusão.',
                ]);
            }
            if ($old==='closed' && !in_array($request->attributes->get('nexa_role'),['owner','admin','manager','supervisor'],true)) {
                abort(403,'Reabertura de chamado exige supervisão.');
            }
            if ($ticket->signature()->exists()
                && (($old === 'closed' && $new === 'triage')
                    || ($old === 'resolved' && $new === 'in_progress'))) {
                throw ValidationException::withMessages([
                    'status' => 'Atendimento já assinado. Abra um novo chamado para preservar o aceite original.',
                ]);
            }
            if (array_key_exists('assigned_user_id',$data)) {
                $this->validateAssignee($tenant,$data['assigned_user_id']===null?null:(int)$data['assigned_user_id']);
                $ticket->assigned_user_id=$data['assigned_user_id'];
            }
            if ($new==='in_progress' && $ticket->assigned_user_id===null) {
                throw ValidationException::withMessages([
                    'assigned_user_id'=>'Atribua um técnico antes de iniciar atendimento.',
                ]);
            }
            $before=$ticket->only(['status','assigned_user_id','first_responded_at','resolved_at','closed_at']);
            $ticket->status=$new;
            if ($new==='in_progress' && $ticket->first_responded_at===null) {
                $ticket->first_responded_at=now();
            }
            if ($new==='resolved') $ticket->resolved_at=now();
            if ($new==='closed') $ticket->closed_at=now();
            if ($old==='closed') {
                $ticket->closed_at=null;
                $ticket->resolved_at=null;
            }
            if ($old==='resolved' && $new==='in_progress') $ticket->resolved_at=null;
            $ticket->save();
            $this->audit->write($tenant,$actor,'ticket.transitioned',$ticket,$before,[
                ...$ticket->only(['status','assigned_user_id','first_responded_at','resolved_at','closed_at']),
                'reason'=>$data['reason'],
            ]);
            return $this->detail($ticket);
        },3);
    }

    public function comment(Request $request,int $id,array $data): ServiceTicketComment
    {
        $tenant=$request->attributes->get('nexa_tenant');
        $actor=$request->user();
        return DB::transaction(function () use ($request,$tenant,$actor,$id,$data): ServiceTicketComment {
            $ticket=ServiceTicket::query()->where('tenant_id',$tenant->id)->findOrFail($id);
            $this->assertVisible($request,$ticket);
            if ($ticket->status==='closed') {
                throw ValidationException::withMessages(['ticket'=>'Chamado encerrado não aceita comentários.']);
            }
            $comment=ServiceTicketComment::query()->create([
                'tenant_id'=>$tenant->id,'ticket_id'=>$ticket->id,
                'author_user_id'=>$actor->id,'internal'=>$data['internal'],'body'=>$data['body'],
            ]);
            $this->audit->write($tenant,$actor,'ticket.comment.created',$comment,null,[
                'ticket_id'=>$ticket->id,'internal'=>$data['internal'],
                // Avoid recording private comment content twice in audit.
            ]);
            return $comment->load('author:id,name');
        });
    }

    public function assertVisible(Request $request,ServiceTicket $ticket): void
    {
        if ($ticket->customer_id===null) {
            $this->portfolio->assertFull($request);
        } else {
            $this->portfolio->assertCustomer($request,(int)$ticket->customer_id);
        }
    }

    public function detail(ServiceTicket $ticket): ServiceTicket
    {
        return $ticket->load([
            'customer:id,name','printer:id,manufacturer,model',
            'assignee:id,name','opener:id,name,email','comments'=>fn ($q)=>$q->with('author:id,name')->orderBy('id'),
            'linkedPrinters:id,manufacturer,model,serial_number,ip_address',
            'attachments:id,tenant_id,ticket_id,original_name,mime_type,bytes,created_at',
            'signature',
            'partRequests.stockItem:id,sku,name',
        ]);
    }

    private function validateLinks(Request $request,Tenant $tenant,array $data): void
    {
        $customerId=$data['customer_id'];
        $printerId=$data['printer_id'];
        if ($customerId===null) {
            $this->portfolio->assertFull($request);
            if ($printerId!==null) {
                throw ValidationException::withMessages(['printer_id'=>'Impressora exige cliente.']);
            }
        } else {
            $this->portfolio->assertCustomer($request,(int)$customerId);
            if (!$tenant->customers()->whereKey($customerId)->where('active',true)->exists()) {
                throw ValidationException::withMessages(['customer_id'=>'Cliente inativo ou desconhecido.']);
            }
            if ($printerId!==null && !$tenant->printers()
                ->whereKey($printerId)->where('customer_id',$customerId)
                ->where('active',true)->exists()) {
                throw ValidationException::withMessages(['printer_id'=>'Impressora não pertence ao cliente ativo.']);
            }
        }
        $this->validateAssignee($tenant,isset($data['assigned_user_id'])?(int)$data['assigned_user_id']:null);
    }

    private function validateAssignee(Tenant $tenant,?int $assigneeId): void
    {
        if ($assigneeId!==null && !$tenant->users()->whereKey($assigneeId)
            ->where('users.active',true)->wherePivotIn('role',['technician'])
            ->exists()) {
            throw ValidationException::withMessages(['assigned_user_id'=>'Responsável deve ser um técnico ativo desta empresa.']);
        }
    }
}
