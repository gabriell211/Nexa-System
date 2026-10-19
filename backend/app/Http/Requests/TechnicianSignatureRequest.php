<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TechnicianSignatureRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'signer_name'=>['required','string','min:3','max:180'],
            'service_summary'=>['required','string','min:10','max:4000'],
            'signature_png_base64'=>['required','string','max:400000'],
            'consent'=>['required','accepted'],
        ];
    }
}
