<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StaffPortfolioRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $tenant = $this->attributes->get('nexa_tenant');
        return [
            'scope' => ['required', Rule::in(['all', 'assigned'])],
            'customer_ids' => ['present', 'array', 'max:200'],
            'customer_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('customers', 'id')->where('tenant_id', $tenant->id),
            ],
        ];
    }
}
