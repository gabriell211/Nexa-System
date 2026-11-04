<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CollectorPrinterInventoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'collector_id' => ['required', 'uuid'],
            'printers' => ['required', 'array', 'min:1', 'max:100'],
            'printers.*.printer_id' => ['required', 'integer', 'min:1', 'distinct'],
            'printers.*.ip_address' => ['present', 'nullable', 'ipv4'],
            'printers.*.manufacturer' => ['present', 'nullable', 'string', 'max:100'],
            'printers.*.model' => ['present', 'nullable', 'string', 'max:160'],
            'printers.*.serial_number' => ['present', 'nullable', 'string', 'max:160'],
            'printers.*.mac_address' => [
                'present', 'nullable', 'string', 'regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/D',
            ],
            'printers.*.monitored' => ['required', 'boolean'],
        ];
    }
}
