<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\CustomerPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class StockOperatorController extends Controller
{
    public function __construct(private readonly CustomerPortfolio $portfolio) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $members = $request->attributes->get('nexa_tenant')->users()
            ->where('users.active', true)->select('users.id', 'users.name')
            ->orderBy('users.name')->paginate($data['per_page'] ?? 25);

        return response()->json($members->through(static fn ($user): array => [
            'id' => $user->id, 'name' => $user->name, 'active' => true,
        ]));
    }
}
