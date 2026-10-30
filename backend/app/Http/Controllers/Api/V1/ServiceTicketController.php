<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\ServiceTicketAssignmentRequest;
use App\Http\Requests\ServiceTicketRequest;
use App\Http\Requests\ServiceTicketTransitionRequest;
use App\Http\Requests\ServiceTicketCommentRequest;
use App\Http\Resources\ServiceTicketResource;
use App\Models\ServiceTicket;
use App\Services\CustomerPortfolio;
use App\Services\ServiceTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class ServiceTicketController extends Controller
{
    public function __construct(
        private readonly ServiceTicketService $tickets,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters=$request->validate([
            'page'=>['sometimes','integer','min:1'],
            'per_page'=>['sometimes','integer','between:1,100'],
            'status'=>['sometimes','string','in:triage,unassigned,pending,in_progress,resolved,closed'],
            'q'=>['sometimes','string','max:100'],
            'printer_id'=>['sometimes','integer','min:1'],
            'customer_id'=>['sometimes','integer','min:1'],
            'open_only'=>['sometimes','in:0,1,true,false'],
        ]);
        $tenant=$request->attributes->get('nexa_tenant');
        $query=ServiceTicket::query()->where('tenant_id',$tenant->id);
        if (!$this->portfolio->hasFullAccess($request)) {
            $query->whereIn('customer_id',$this->portfolio->assignedIds($request));
        }
        return ServiceTicketResource::collection(
            $query->with(['customer:id,name','assignee:id,name','opener:id,name,email'])
                ->when(isset($filters['status']),fn ($q)=>$q->where('status',$filters['status']))
                ->when(isset($filters['printer_id']),fn ($q)=>$q->where('printer_id',(int)$filters['printer_id']))
                ->when(isset($filters['customer_id']),fn ($q)=>$q->where('customer_id',(int)$filters['customer_id']))
                ->when($request->boolean('open_only'),fn ($q)=>$q->whereNotIn('status',['resolved','closed']))
                ->when(isset($filters['q']),fn ($q)=>$q->where('subject','like','%'.$filters['q'].'%'))
                ->orderByDesc('id')->paginate($filters['per_page']??25)
        );
    }

    public function show(Request $request,int $ticket): ServiceTicketResource
    {
        $record=ServiceTicket::query()
            ->where('tenant_id',$request->attributes->get('nexa_tenant')->id)->findOrFail($ticket);
        $this->tickets->assertVisible($request,$record);
        return new ServiceTicketResource($this->tickets->detail($record));
    }

    public function store(ServiceTicketRequest $request): JsonResponse
    {
        $record=$this->tickets->create($request,$request->validated());
        return (new ServiceTicketResource($record))->response()->setStatusCode(201);
    }

    public function assign(ServiceTicketAssignmentRequest $request, int $ticket): ServiceTicketResource
    {
        return new ServiceTicketResource(
            $this->tickets->assign($request, $ticket, $request->validated())
        );
    }

    public function transition(ServiceTicketTransitionRequest $request,int $ticket): ServiceTicketResource
    {
        return new ServiceTicketResource(
            $this->tickets->transition($request,$ticket,$request->validated())
        );
    }

    public function comment(ServiceTicketCommentRequest $request,int $ticket): JsonResponse
    {
        $comment=$this->tickets->comment($request,$ticket,$request->validated());
        return response()->json(['data'=>[
            'id'=>$comment->id,'body'=>$comment->body,'internal'=>$comment->internal,
            'created_at'=>$comment->created_at,
            'author'=>$comment->author?->only(['id','name']),
        ]],201);
    }
}
