<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockCountRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'item_id' => ['required', 'integer', 'min:1'],
            'location_id' => ['required', 'integer', 'min:1'],
            'counted_quantity' => ['required', 'integer', 'between:0,1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
