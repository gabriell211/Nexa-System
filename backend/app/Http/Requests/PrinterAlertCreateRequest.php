<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PrinterAlertCreateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'printer_id' => ['required','integer','min:1'],
            'code' => ['required','string','regex:/^[A-Z0-9][A-Z0-9_.-]{1,63}$/','max:64'],
            'category' => ['required',Rule::in(['hardware','communication','maintenance','supplies','other'])],
            'severity' => ['required',Rule::in(['info','warning','critical'])],
            'title' => ['required','string','min:4','max:180'],
            'description' => ['sometimes','nullable','string','max:5000'],
        ];
    }
}
