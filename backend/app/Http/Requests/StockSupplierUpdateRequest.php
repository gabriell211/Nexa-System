<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StockSupplierUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // An existing supplier code is a stable reference in fiscal history.
            'code' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:180'],
            'email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'document' => ['sometimes', 'nullable', 'string', 'max:30'],
            'active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
