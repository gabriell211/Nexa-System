<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PrinterReadingHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role, tenant and customer portfolio are enforced by route middleware
        // and again by the controller's scoped lookup.
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $start=$this->input('start_date');
                $end=$this->input('end_date');
                if (is_string($start) && is_string($end)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$start)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$end)
                    && $end < $start) {
                    $validator->errors()->add(
                        'end_date','A data final não pode ser anterior à data inicial.'
                    );
                }
            },
        ];
    }
}
