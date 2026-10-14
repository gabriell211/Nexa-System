<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PrinterAlertTransitionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'status' => ['required',Rule::in(['acknowledged','resolved','ignored'])],
            'reason' => ['required','string','min:5','max:500'],
        ];
    }
}
