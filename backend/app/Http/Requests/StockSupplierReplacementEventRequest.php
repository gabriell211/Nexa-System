<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StockSupplierReplacementEventRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'request_id' => ['required','uuid'],
            'expected_version' => ['required','integer','min:1'],
            'action' => ['required',Rule::in(['follow_up','rescheduled','supplier_confirmed','closed_unresolved'])],
            'note' => ['required','string','min:5','max:500'],
            'supplier_reference' => ['required_if:action,supplier_confirmed','nullable','string','min:3','max:120'],
            'due_on' => ['required_if:action,rescheduled','nullable','date_format:Y-m-d'],
        ];
    }
}
