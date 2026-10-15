<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockPurchaseReceiptMatchRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'purchase_order_line_id' => ['required', 'integer', 'min:1'],
            'receipt_detail_id' => ['required', 'integer', 'min:1'],
            'variance_reason' => ['sometimes', 'nullable', 'string', 'min:5', 'max:500'],
        ];
    }
}
