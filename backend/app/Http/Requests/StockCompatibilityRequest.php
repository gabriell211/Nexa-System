<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockCompatibilityRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'printer_manufacturer'=>['required','string','min:2','max:120'],
            'printer_model'=>['required','string','min:2','max:160'],
        ];
    }
}
