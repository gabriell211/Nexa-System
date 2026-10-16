<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CustomerContractRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        return [
            'customer_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'document_number' => [$required, 'string', 'min:1', 'max:80'],
            'title' => [$required, 'string', 'min:2', 'max:180'],
            'provider_unit_name' => [$required, 'string', 'min:2', 'max:180'],
            'billing_address' => [$required, 'string', 'min:5', 'max:500'],
            'billing_timezone' => [$required, 'timezone', 'max:64'],
            'effective_on' => [$required, 'date_format:Y-m-d'],
            'billing_starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expected_version' => [$this->isMethod('post') ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }
}
