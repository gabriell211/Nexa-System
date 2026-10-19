<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ServiceTicketPartRequest;
use App\Services\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TicketPartRequestReviewController extends Controller
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filter=$request->validate([
            'status'=>['sometimes',Rule::in(['pending','approved','rejected'])],
        ]);
        $query=ServiceTicketPartRequest::query()
            ->where('tenant_id',$request->attributes->get('nexa_tenant')->id)
            ->with(['stockItem:id,name,sku','technician:id,name'])
            ->when(isset($filter['status']),fn($query)=>$query->where('status',$filter['status']))
            ->orderByDesc('id')->paginate(25);
        return response()->json($query)->header('Cache-Control','no-store, private');
    }

    public function review(Request $request,int $part): JsonResponse
    {
        $data=$request->validate([
            'status'=>['required',Rule::in(['approved','rejected'])],
            'review_note'=>['required','string','min:5','max:500'],
        ]);
        $record=DB::transaction(function () use($request,$part,$data): ServiceTicketPartRequest {
            $tenant=$request->attributes->get('nexa_tenant');
            $record=ServiceTicketPartRequest::query()->where('tenant_id',$tenant->id)
                ->lockForUpdate()->findOrFail($part);
            if ($record->status!=='pending') {
                throw ValidationException::withMessages(['status'=>'Solicitação já analisada.']);
            }
            $record->fill([
                'status'=>$data['status'],'review_note'=>$data['review_note'],
                'reviewed_by_user_id'=>$request->user()->id,'reviewed_at'=>now(),
            ])->save();
            $this->audit->write($tenant,$request->user(),'ticket.part.reviewed',$record,
                ['status'=>'pending'],['status'=>$record->status,'ticket_id'=>$record->ticket_id]);
            return $record;
        },3);
        return response()->json(['data'=>$record->load('stockItem:id,name,sku')])
            ->header('Cache-Control','no-store, private');
    }
}
