<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockShipmentEventRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'request_id'=>['required','uuid'],
            'quantity'=>['required','integer','between:1,1000000'],
            'reason'=>['required','string','min:5','max:500'],
        ];
    }
}
