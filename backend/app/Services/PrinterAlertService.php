<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Printer;
use App\Models\PrinterAlert;
use App\Models\ServiceTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PrinterAlertService
{
    private const ACTIVE = ['active','acknowledged','linked'];
    private const TRANSITIONS = [
        'active'=>['acknowledged','resolved','ignored'],
        'acknowledged'=>['resolved','ignored'],
        'linked'=>['resolved','ignored'],
    ];

    public function __construct(
        private readonly CustomerPortfolio $portfolio,
        private readonly AuditWriter $audit,
    ) {}

    public function open(Request $request,array $data): array
    {
        $tenant=$request->attributes->get('nexa_tenant');
        $actor=$request->user();

        return DB::transaction(function () use ($request,$tenant,$actor,$data): array {
            // Serializes incidents by printer on PostgreSQL; SQLite serializes writes.
            $printer=Printer::query()->where('tenant_id',$tenant->id)
                ->where('active',true)->lockForUpdate()->findOrFail($data['printer_id']);
            $this->portfolio->assertCustomer($request,(int)$printer->customer_id);

            $existing=PrinterAlert::query()->where('tenant_id',$tenant->id)
                ->where('printer_id',$printer->id)->where('origin','operator')
                ->where('code',$data['code'])->whereIn('status',self::ACTIVE)->first();
            if ($existing!==null) return [$this->detail($existing),true];

            $alert=PrinterAlert::query()->create([
                'tenant_id'=>$tenant->id,
                'customer_id'=>$printer->customer_id,
                'printer_id'=>$printer->id,
                'created_by_user_id'=>$actor->id,
                'origin'=>'operator',
                'code'=>$data['code'],
                'category'=>$data['category'],
                'severity'=>$data['severity'],
                'status'=>'active',
                'title'=>$data['title'],
                'description'=>$data['description']??null,
            ]);
            $this->audit->write($tenant,$actor,'alert.opened',$alert,null,
                $alert->only(['printer_id','code','category','severity','status','origin']));
            return [$this->detail($alert),false];
        },3);
    }

    public function assertVisible(Request $request,PrinterAlert $alert): void
    {
        $this->portfolio->assertCustomer($request,(int)$alert->customer_id);
    }

    public function detail(PrinterAlert $alert): PrinterAlert
    {
        return $alert->load([
            'customer:id,name','printer:id,manufacturer,model',
            'ticket:id,subject,status','creator:id,name',
        ]);
    }

    public function transition(Request $request,int $id,array $data): PrinterAlert
    {
        $tenant=$request->attributes->get('nexa_tenant');
        $actor=$request->user();

        return DB::transaction(function () use ($request,$tenant,$actor,$id,$data): PrinterAlert {
            $alert=PrinterAlert::query()->where('tenant_id',$tenant->id)
                ->lockForUpdate()->findOrFail($id);
            $this->assertVisible($request,$alert);
            $new=$data['status'];
            if (!in_array($new,self::TRANSITIONS[$alert->status]??[],true)) {
                throw ValidationException::withMessages(['status'=>'Transição de alerta não permitida.']);
            }
            $before=$alert->only(['status','ticket_id','acknowledged_at','resolved_at','ignored_at']);
            $alert->status=$new;
            if ($new==='acknowledged') $alert->acknowledged_at=now();
            if ($new==='resolved') $alert->resolved_at=now();
            if ($new==='ignored') $alert->ignored_at=now();
            $alert->save();
            $this->audit->write($tenant,$actor,'alert.transitioned',$alert,$before,[
                ...$alert->only(['status','ticket_id','acknowledged_at','resolved_at','ignored_at']),
                'reason'=>$data['reason'],
            ]);
            return $this->detail($alert);
        },3);
    }

    public function linkTicket(Request $request,int $id,int $ticketId): PrinterAlert
    {
        $tenant=$request->attributes->get('nexa_tenant');
        $actor=$request->user();
        return DB::transaction(function () use ($request,$tenant,$actor,$id,$ticketId): PrinterAlert {
            $alert=PrinterAlert::query()->where('tenant_id',$tenant->id)
                ->lockForUpdate()->findOrFail($id);
            $this->assertVisible($request,$alert);
            if ($alert->status==='linked' && (int)$alert->ticket_id===$ticketId) {
                return $this->detail($alert);
            }
            if (!in_array($alert->status,['active','acknowledged'],true) || $alert->ticket_id!==null) {
                throw ValidationException::withMessages(['alert'=>'Alerta não aceita novo vínculo.']);
            }
            $ticket=ServiceTicket::query()->where('tenant_id',$tenant->id)
                ->where('customer_id',$alert->customer_id)
                ->where('printer_id',$alert->printer_id)
                ->whereNotIn('status',['resolved','closed'])->find($ticketId);
            if ($ticket===null) {
                throw ValidationException::withMessages([
                    'ticket_id'=>'Chamado deve estar aberto e pertencer à mesma impressora e cliente.',
                ]);
            }
            $this->portfolio->assertCustomer($request,(int)$ticket->customer_id);
            $before=$alert->only(['status','ticket_id']);
            $alert->ticket_id=$ticket->id;
            $alert->status='linked';
            $alert->save();
            $this->audit->write($tenant,$actor,'alert.linked',$alert,$before,
                $alert->only(['status','ticket_id']));
            return $this->detail($alert);
        },3);
    }
}
