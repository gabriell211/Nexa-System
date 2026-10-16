<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockSupplierReplacementReceiptRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'purchase_receipt_match_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
