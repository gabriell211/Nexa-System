<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\TechnicianPartRequest;
use App\Http\Requests\TechnicianSignatureRequest;
use App\Http\Resources\ServiceTicketResource;
use App\Models\ServiceTicket;
use App\Models\StockItem;
use App\Services\TechnicianFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class MobileTechnicianController extends Controller
{
    public function __construct(private readonly TechnicianFieldService $field) {}

    public function tickets(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['page'=>['sometimes','integer','min:1']]);
        $tenant = $request->attributes->get('nexa_tenant');
        return ServiceTicketResource::collection(ServiceTicket::query()
            ->where('tenant_id',$tenant->id)->where('assigned_user_id',$request->user()->id)
            ->whereNotIn('status',['closed'])
            ->with(['customer:id,name','printer:id,manufacturer,model'])
            ->orderByDesc('id')->paginate(25));
    }

    public function show(Request $request,int $ticket): ServiceTicketResource
    {
        return new ServiceTicketResource($this->field->detail($this->field->assigned($request,$ticket)));
    }

    public function start(Request $request,int $ticket): ServiceTicketResource
    {
        return new ServiceTicketResource($this->field->start($request,$ticket));
    }

    public function stockItems(Request $request): JsonResponse
    {
        $data = $request->validate(['q'=>['sometimes','string','max:80']]);
        $query = StockItem::query()->where('tenant_id',$request->attributes->get('nexa_tenant')->id)
            ->where('active',true)->select(['id','sku','name','unit']);
        if (!empty($data['q'])) {
            $q = '%'.str_replace(['%','_'],['\\%','\\_'],$data['q']).'%';
            $query->where(fn($query)=>$query->where('name','like',$q)->orWhere('sku','like',$q));
        }
        return response()->json(['data'=>$query->orderBy('name')->limit(50)->get()])
            ->header('Cache-Control','no-store, private');
    }

    public function requestPart(TechnicianPartRequest $request,int $ticket): JsonResponse
    {
        $result = $this->field->requestPart($request,$ticket,$request->validated());
        $part = $result['request'];
        return response()->json(['data'=>[
            'id'=>$part->id,'ticket_id'=>$part->ticket_id,'stock_item_id'=>$part->stock_item_id,
            'quantity'=>$part->quantity,'reason'=>$part->reason,'status'=>$part->status,
            'item'=>$part->stockItem?->only(['id','sku','name']),
        ]], $result['created'] ? 201 : 200)->header('Cache-Control','no-store, private');
    }

    public function sign(TechnicianSignatureRequest $request,int $ticket): ServiceTicketResource
    {
        return new ServiceTicketResource($this->field->sign($request,$ticket,$request->validated()));
    }
}
