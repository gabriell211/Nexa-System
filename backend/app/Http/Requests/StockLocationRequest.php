<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StockLocationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        return [
            'name' => [$required, 'string', 'min:2', 'max:180'],
            'kind' => [$required, Rule::in(['provider', 'technician', 'customer'])],
            'customer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'customer_location_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'technician_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
