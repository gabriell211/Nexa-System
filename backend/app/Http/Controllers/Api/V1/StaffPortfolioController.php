<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StaffPortfolioRequest;
use App\Services\StaffPortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

final class StaffPortfolioController extends Controller
{
    public function __construct(private readonly StaffPortfolioService $portfolios) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $tenant = $request->attributes->get('nexa_tenant');
        $page = $tenant->users()->select('users.id', 'users.name', 'users.email', 'users.active')
            ->orderBy('users.name')->paginate($filters['per_page'] ?? 25);
        return response()->json($page->through(static fn ($user): array => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'active' => (bool) $user->active,
            'role' => $user->pivot->role,
            'customer_scope' => $user->pivot->customer_scope,
        ]));
    }

    public function show(Request $request, int $user): JsonResponse
    {
        $tenant = $request->attributes->get('nexa_tenant');
        $member = $tenant->users()->whereKey($user)->firstOrFail();
        $ids = DB::table('customer_user_access')
            ->where('tenant_id', $tenant->id)->where('user_id', $member->id)
            ->orderBy('customer_id')->pluck('customer_id')->map(fn ($id) => (int) $id);
        return response()->json([
            'user' => $member->only(['id','name','email']),
            'role' => $member->pivot->role,
            'scope' => $member->pivot->customer_scope,
            'customer_ids' => $ids,
        ]);
    }

    public function update(StaffPortfolioRequest $request, int $user): JsonResponse
    {
        $data = $request->validated();
        return response()->json($this->portfolios->update(
            $request->attributes->get('nexa_tenant'),
            $request->user(), $user, $data['scope'], $data['customer_ids']
        ));
    }
}
