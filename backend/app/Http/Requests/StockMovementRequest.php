<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StockMovementRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['receipt', 'transfer', 'issue'])],
            'item_id' => ['required', 'integer', 'min:1'],
            'source_location_id' => ['present', 'nullable', 'integer', 'min:1'],
            'destination_location_id' => ['present', 'nullable', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'between:1,1000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
