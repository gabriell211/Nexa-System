<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockReceiptBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'invoice_reference' => ['required', 'string', 'min:1', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.item_id' => ['required', 'integer', 'min:1'],
            'items.*.destination_location_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'between:1,1000000'],
            'items.*.lot_number' => ['required', 'string', 'min:1', 'max:100'],
            'items.*.expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'items.*.unit_cost_cents' => ['required', 'integer', 'between:0,1000000000'],
        ];
    }
}
