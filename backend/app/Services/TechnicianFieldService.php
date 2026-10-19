<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\ServiceTicket;
use App\Models\ServiceTicketPartRequest;
use App\Models\ServiceTicketSignature;
use App\Models\StockItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TechnicianFieldService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function assigned(Request $request, int $id, bool $lock = false): ServiceTicket
    {
        $query = ServiceTicket::query()->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
            ->where('assigned_user_id', $request->user()->id);
        if ($lock) $query->lockForUpdate();
        return $query->findOrFail($id);
    }

    public function detail(ServiceTicket $ticket): ServiceTicket
    {
        return $ticket->load([
            'customer:id,name','printer:id,manufacturer,model,serial_number,ip_address',
            'partRequests.stockItem:id,name,sku','signature',
        ]);
    }

    public function start(Request $request, int $id): ServiceTicket
    {
        return DB::transaction(function () use ($request,$id): ServiceTicket {
            $ticket = $this->assigned($request,$id,true);
            if (!in_array($ticket->status, ['triage','unassigned','pending'], true)) {
                throw ValidationException::withMessages(['status'=>'O chamado não pode ser iniciado neste estado.']);
            }
            $before = $ticket->status;
            $ticket->status = 'in_progress';
            $ticket->first_responded_at ??= now();
            $ticket->save();
            $this->audit->write($request->attributes->get('nexa_tenant'),$request->user(),
                'ticket.technician.started',$ticket,['status'=>$before],['status'=>$ticket->status]);
            return $this->detail($ticket);
        },3);
    }

    /** @return array{request: ServiceTicketPartRequest, created: bool} */
    public function requestPart(Request $request, int $id, array $data): array
    {
        return DB::transaction(function () use ($request,$id,$data): array {
            $ticket = $this->assigned($request,$id,true);
            if (in_array($ticket->status,['resolved','closed'],true)) {
                throw ValidationException::withMessages(['ticket'=>'Chamado já finalizado.']);
            }
            $tenant = $request->attributes->get('nexa_tenant');
            $existing = ServiceTicketPartRequest::query()->where('tenant_id',$tenant->id)
                ->where('request_id',$data['request_id'])->first();
            if ($existing) {
                if ((int)$existing->ticket_id !== $id
                    || (int)$existing->requested_by_user_id !== (int)$request->user()->id
                    || (int)$existing->stock_item_id !== (int)$data['stock_item_id']
                    || (int)$existing->quantity !== (int)$data['quantity']
                    || $existing->reason !== $data['reason']) {
                    abort(409,'Identificador já utilizado por outra solicitação.');
                }
                return ['request'=>$existing->load('stockItem:id,name,sku'),'created'=>false];
            }
            StockItem::query()->where('tenant_id',$tenant->id)->where('active',true)
                ->findOrFail($data['stock_item_id']);
            $part = ServiceTicketPartRequest::query()->create([
                'tenant_id'=>$tenant->id,'ticket_id'=>$ticket->id,
                'stock_item_id'=>$data['stock_item_id'],'requested_by_user_id'=>$request->user()->id,
                'request_id'=>$data['request_id'],'quantity'=>$data['quantity'],
                'reason'=>$data['reason'],'status'=>'pending',
            ]);
            $this->audit->write($tenant,$request->user(),'ticket.part.requested',$part,null,[
                'ticket_id'=>$id,'stock_item_id'=>$part->stock_item_id,'quantity'=>$part->quantity,
            ]);
            return ['request'=>$part->load('stockItem:id,name,sku'),'created'=>true];
        },3);
    }

    public function sign(Request $request,int $id,array $data): ServiceTicket
    {
        $png = base64_decode($data['signature_png_base64'],true);
        if ($png === false || strlen($png) > 250000 || strlen($png) < 100
            || !str_starts_with($png,"\x89PNG\r\n\x1a\n")) {
            throw ValidationException::withMessages(['signature_png_base64'=>'Assinatura PNG inválida.']);
        }
        $dimensions = @getimagesizefromstring($png);
        if (!$dimensions || $dimensions[2] !== IMAGETYPE_PNG || $dimensions[0] < 200
            || $dimensions[1] < 80 || $dimensions[0] > 1600 || $dimensions[1] > 1000) {
            throw ValidationException::withMessages(['signature_png_base64'=>'Dimensões de assinatura inválidas.']);
        }
        $path = null;
        try {
            return DB::transaction(function () use ($request,$id,$data,$png,&$path): ServiceTicket {
                $ticket = $this->assigned($request,$id,true);
                if ($ticket->status !== 'in_progress') {
                    throw ValidationException::withMessages(['status'=>'Inicie o atendimento antes de coletar assinatura.']);
                }
                if (ServiceTicketSignature::query()->where('tenant_id',$ticket->tenant_id)
                    ->where('ticket_id',$ticket->id)->exists()) {
                    abort(409,'Este chamado já possui assinatura.');
                }
                $path = 'ticket-signatures/'.$ticket->tenant_id.'/'.$ticket->id.'/'.Str::uuid().'.png';
                if (!Storage::disk('local')->put($path,$png)) {
                    throw new \RuntimeException('Falha ao armazenar a assinatura.');
                }
                $signature = ServiceTicketSignature::query()->create([
                    'tenant_id'=>$ticket->tenant_id,'ticket_id'=>$ticket->id,
                    'technician_user_id'=>$request->user()->id,
                    'signer_name'=>$data['signer_name'],'service_summary'=>$data['service_summary'],
                    'storage_path'=>$path,'sha256'=>hash('sha256',$png),
                    'signed_at'=>now(),'ip_address'=>$request->ip(),
                    'user_agent'=>mb_substr($request->userAgent() ?? '',0,255),
                ]);
                $ticket->status = 'resolved';
                $ticket->resolved_at = now();
                $ticket->save();
                $this->audit->write($request->attributes->get('nexa_tenant'),$request->user(),
                    'ticket.customer.signed',$signature,null,[
                        'ticket_id'=>$ticket->id,'sha256'=>$signature->sha256,
                        'signer_name'=>$signature->signer_name,'status'=>$ticket->status,
                    ]);
                return $this->detail($ticket);
            },3);
        } catch (\Throwable $e) {
            if ($path !== null) Storage::disk('local')->delete($path);
            throw $e;
        }
    }
}
