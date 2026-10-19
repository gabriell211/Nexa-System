<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\CustomerPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ServiceTicketOperatorController extends Controller
{
    public function __construct(private readonly CustomerPortfolio $portfolio) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data=$request->validate([
            'page'=>['sometimes','integer','min:1'],
            'per_page'=>['sometimes','integer','between:1,100'],
        ]);
        $tenant=$request->attributes->get('nexa_tenant');
        $query=$tenant->users()->where('users.active',true)
            ->wherePivotIn('role',['owner','admin','manager','supervisor','technician'])
            ->select('users.id','users.name');
        if (!$this->portfolio->hasFullAccess($request)) {
            $query->where('users.id',$request->user()->id);
        }
        $operators=$query->orderBy('users.name')->paginate($data['per_page']??25);
        return response()->json($operators->through(
            static fn ($user): array => [
                ...$user->only(['id','name']),
                'role'=>$user->pivot->role,
            ]
        ));
    }
}
