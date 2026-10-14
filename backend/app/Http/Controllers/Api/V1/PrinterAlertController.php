<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\PrinterAlertCreateRequest;
use App\Http\Requests\PrinterAlertLinkRequest;
use App\Http\Requests\PrinterAlertTransitionRequest;
use App\Http\Resources\PrinterAlertResource;
use App\Models\PrinterAlert;
use App\Services\CustomerPortfolio;
use App\Services\PrinterAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class PrinterAlertController extends Controller
{
    public function __construct(
        private readonly PrinterAlertService $alerts,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters=$request->validate([
            'status'=>['sometimes','string','in:active,acknowledged,linked,resolved,ignored'],
            'severity'=>['sometimes','string','in:info,warning,critical'],
            'printer_id'=>['sometimes','integer','min:1'],
            'q'=>['sometimes','nullable','string','max:100'],
            'page'=>['sometimes','integer','min:1'],
            'per_page'=>['sometimes','integer','between:1,100'],
        ]);
        $tenant=$request->attributes->get('nexa_tenant');
        $query=PrinterAlert::query()->where('tenant_id',$tenant->id);
        if (!$this->portfolio->hasFullAccess($request)) {
            $query->whereIn('customer_id',$this->portfolio->assignedIds($request));
        }
        $term=trim($filters['q']??'');
        return PrinterAlertResource::collection(
            $query->with(['customer:id,name','printer:id,manufacturer,model','ticket:id,subject,status'])
                ->when(isset($filters['status']),fn($q)=>$q->where('status',$filters['status']))
                ->when(isset($filters['severity']),fn($q)=>$q->where('severity',$filters['severity']))
                ->when(isset($filters['printer_id']),fn($q)=>$q->where('printer_id',$filters['printer_id']))
                ->when($term!=='',fn($q)=>$q->where('title','like','%'.$term.'%'))
                ->orderByDesc('id')->paginate($filters['per_page']??25)
        );
    }

    public function show(Request $request,int $alert): PrinterAlertResource
    {
        $item=PrinterAlert::query()->where('tenant_id',$request->attributes->get('nexa_tenant')->id)
            ->findOrFail($alert);
        $this->alerts->assertVisible($request,$item);
        return new PrinterAlertResource($this->alerts->detail($item));
    }

    public function store(PrinterAlertCreateRequest $request): JsonResponse
    {
        [$alert,$deduplicated]=$this->alerts->open($request,$request->validated());
        return (new PrinterAlertResource($alert))->additional(['deduplicated'=>$deduplicated])
            ->response()->setStatusCode($deduplicated?200:201);
    }

    public function transition(PrinterAlertTransitionRequest $request,int $alert): PrinterAlertResource
    {
        return new PrinterAlertResource(
            $this->alerts->transition($request,$alert,$request->validated())
        );
    }

    public function linkTicket(PrinterAlertLinkRequest $request,int $alert): PrinterAlertResource
    {
        return new PrinterAlertResource(
            $this->alerts->linkTicket($request,$alert,(int)$request->validated('ticket_id'))
        );
    }
}
