<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockSupplierReplacementCaseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'request_id' => ['required','uuid'],
            'supplier_return_id' => ['required','integer','min:1'],
            'assigned_user_id' => ['required','integer','min:1'],
            'due_on' => ['required','date_format:Y-m-d'],
            'note' => ['required','string','min:5','max:500'],
        ];
    }
}
