<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CollectorIngestRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'collector_id' => ['required', 'uuid'],
            'samples' => ['required', 'array', 'min:1', 'max:100'],
            'samples.*.sample_id' => ['required', 'uuid', 'distinct'],
            'samples.*.printer_id' => ['required', 'integer', 'min:1'],
            'samples.*.collected_at' => ['required', 'date'],
            'samples.*.meter_total' => ['present', 'nullable', 'integer', 'min:0'],
            'samples.*.meter_mono' => ['present', 'nullable', 'integer', 'min:0'],
            'samples.*.meter_color' => ['present', 'nullable', 'integer', 'min:0'],
        ];
    }
}
