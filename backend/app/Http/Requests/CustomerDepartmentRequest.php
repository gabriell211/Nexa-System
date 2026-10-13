<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CustomerDepartmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        $code = Rule::unique('customer_departments', 'code')
            ->where('location_id', (int) $this->route('location'));
        $department = (int) ($this->route('department') ?? 0);
        if ($department > 0) $code->ignore($department);

        return [
            'name' => [$required, 'string', 'min:2', 'max:180'],
            'code' => ['sometimes', 'nullable', 'string', 'max:64', $code],
            'responsible_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'responsible_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
