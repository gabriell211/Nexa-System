<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class PrinterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('nexa_tenant');
        return response()->json($tenant->printers()->with('customer:id,name')->orderByDesc('id')->paginate(25));
    }
    public function store(Request $request): JsonResponse
    {
        abort_unless(in_array($request->attributes->get('nexa_role'), ['owner','admin','manager'], true), 403);
        $tenant = $request->attributes->get('nexa_tenant');
        $data = $request->validate([
            'customer_id' => ['required','integer',Rule::exists('customers','id')->where('tenant_id',$tenant->id)],
            'manufacturer' => ['required','string','max:100'],
            'model' => ['required','string','max:160'],
            'serial_number' => ['nullable','string','max:160'],
            'ip_address' => ['nullable','ip'],
        ]);
        $printer = $tenant->printers()->create($data + ['status'=>'unknown']);
        return response()->json(['data'=>$printer], 201);
    }
    public function show(Request $request, int $printer): JsonResponse
    {
        $item = $request->attributes->get('nexa_tenant')->printers()
            ->with(['customer:id,name','readings'=>fn ($query) => $query->orderByDesc('collected_at')->limit(20)])
            ->findOrFail($printer);
        return response()->json(['data'=>$item]);
    }
}
