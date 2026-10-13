<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockShipmentCloseRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'request_id'=>['required','uuid'],
            'reason'=>['required','string','min:5','max:500'],
        ];
    }
}
