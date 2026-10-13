<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CostCenterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $tenant = $this->attributes->get('nexa_tenant');
        $code = Rule::unique('cost_centers', 'code')
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', (int) $this->route('customer'));
        $center = (int) ($this->route('costCenter') ?? 0);
        if ($center > 0) $code->ignore($center);

        return [
            'code' => [$required, 'string', 'min:1', 'max:64', $code],
            'name' => [$required, 'string', 'min:2', 'max:180'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
