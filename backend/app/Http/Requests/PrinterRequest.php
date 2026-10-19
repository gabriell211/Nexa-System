<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced by route middleware.
    }

    public function rules(): array
    {
        $tenant = $this->attributes->get('nexa_tenant');
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'customer_id' => [
                $required,
                'integer',
                Rule::exists('customers', 'id')
                    ->where('tenant_id', $tenant->id)
                    ->where('active', true),
            ],
            'manufacturer' => [$required, 'string', 'min:1', 'max:100'],
            'model' => [$required, 'string', 'min:1', 'max:160'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:160'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'asset_number' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[\\pL\\pN._\\/ -]+$/u'],
            'mac_address' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-fA-F]{2}(:[0-9a-fA-F]{2}){5}$/'],
            'firmware_version' => ['sometimes', 'nullable', 'string', 'max:160'],
            'owner_type' => ['sometimes', Rule::in(['unknown', 'customer', 'provider'])],
            'connection_type' => ['sometimes', Rule::in(['unknown', 'network', 'usb'])],
            'color_capability' => ['sometimes', Rule::in(['unknown', 'mono', 'color'])],
        ];
    }
}
