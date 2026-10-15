<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PrinterProductionPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route and controller enforce role, tenant and customer portfolio.
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = CarbonImmutable::parse($this->input('start_date').' 00:00:00', 'UTC');
            $end = CarbonImmutable::parse($this->input('end_date').' 00:00:00', 'UTC');
            if ($end->lessThan($start)) {
                $validator->errors()->add('end_date', 'A data final deve ser igual ou posterior à inicial.');
            } elseif ($start->diffInDays($end) > 30) {
                $validator->errors()->add('end_date', 'A prévia aceita no máximo 31 dias corridos.');
            }
        }];
    }
}
