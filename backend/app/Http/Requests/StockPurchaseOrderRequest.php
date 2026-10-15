<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'request_id' => ['required','uuid'],
            'plan_id' => ['required','integer','min:1'],
            'supplier_id' => ['required','integer','min:1'],
            'notes' => ['sometimes','nullable','string','max:500'],
            'items' => ['required','array','min:1','max:30'],
            'items.*.plan_line_id' => ['required','integer','min:1','distinct'],
            'items.*.quantity' => ['required','integer','between:1,1000000'],
            'items.*.unit_cost_cents' => ['required','integer','between:0,100000000'],
        ];
    }
}
