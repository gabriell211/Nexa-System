<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CustomerLocationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenant = $this->attributes->get('nexa_tenant');
        $customer = (int) $this->route('customer');
        $location = (int) ($this->route('location') ?? 0);
        $code = Rule::unique('customer_locations', 'code')
            ->where('tenant_id', $tenant->id)->where('customer_id', $customer);
        if ($location > 0) $code->ignore($location);

        return [
            'name' => [$required, 'string', 'min:2', 'max:180'],
            'code' => ['sometimes', 'nullable', 'string', 'max:64', $code],
            'document' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:240'],
            'number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'district' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:80'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:24'],
            'country' => ['sometimes', 'string', 'size:2', 'alpha:ascii'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
