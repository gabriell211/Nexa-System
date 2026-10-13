<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockShipmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'request_id'=>['required','uuid'],
            'item_id'=>['required','integer','min:1'],
            'source_location_id'=>['required','integer','min:1'],
            'destination_location_id'=>['required','integer','min:1'],
            'quantity'=>['required','integer','between:1,1000000'],
            'reason'=>['required','string','min:5','max:500'],
        ];
    }
}
