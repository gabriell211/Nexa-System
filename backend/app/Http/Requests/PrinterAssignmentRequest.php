<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PrinterAssignmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'integer', 'min:1'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cost_center_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
