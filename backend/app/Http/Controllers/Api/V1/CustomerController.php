<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('nexa_tenant');
        return response()->json($tenant->customers()->orderBy('name')->paginate(25));
    }
    public function store(Request $request): JsonResponse
    {
        abort_unless(in_array($request->attributes->get('nexa_role'), ['owner','admin','manager'], true), 403);
        $data = $request->validate([
            'name' => ['required','string','max:180'],
            'document' => ['nullable','string','max:32'],
            'email' => ['nullable','email','max:255'],
        ]);
        $customer = $request->attributes->get('nexa_tenant')->customers()->create($data);
        return response()->json(['data' => $customer], 201);
    }
    public function show(Request $request, int $customer): JsonResponse
    {
        $item = $request->attributes->get('nexa_tenant')->customers()->findOrFail($customer);
        return response()->json(['data' => $item]);
    }
}
