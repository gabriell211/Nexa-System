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
        return DB::transaction(function () use ($request,$tenant,$actor,$data): ServiceTicket {
            $this->validateLinks($request,$tenant,$data);
            $ticket=ServiceTicket::query()->create([
                'tenant_id'=>$tenant->id,'customer_id'=>$data['customer_id'],
                'printer_id'=>$data['printer_id'],
                'assigned_user_id'=>$data['assigned_user_id']??null,
                'opened_by_user_id'=>$actor->id,'subject'=>$data['subject'],
                'description'=>$data['description'],'category'=>$data['category'],
                'priority'=>$data['priority'],'status'=>'triage','origin'=>'backoffice',
            ]);
            $this->audit->write($tenant,$actor,'ticket.created',$ticket,null,
                $ticket->only(['customer_id','printer_id','assigned_user_id','subject','priority','status']));
            return $this->detail($ticket);
        });
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
            if (!in_array($new,self::TRANSITIONS[$old]??[],true)) {
                throw ValidationException::withMessages([
                    'status'=>'Transição de estado não permitida.',
                ]);
            }
            if ($old==='closed' && !in_array($request->attributes->get('nexa_role'),['owner','admin','manager','supervisor'],true)) {
                abort(403,'Reabertura de chamado exige supervisão.');
            }
            if (array_key_exists('assigned_user_id',$data)) {
                $this->validateAssignee($tenant,$data['assigned_user_id']);
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
            'assignee:id,name','comments'=>fn ($q)=>$q->with('author:id,name')->orderBy('id'),
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
        $this->validateAssignee($tenant,$data['assigned_user_id']??null);
    }

    private function validateAssignee(Tenant $tenant,?int $assigneeId): void
    {
        if ($assigneeId!==null && !$tenant->users()->whereKey($assigneeId)
            ->where('users.active',true)->wherePivotIn('role',['owner','admin','manager','supervisor','technician'])
            ->exists()) {
            throw ValidationException::withMessages(['assigned_user_id'=>'Responsável não pertence à equipe de atendimento.']);
        }
    }
}
