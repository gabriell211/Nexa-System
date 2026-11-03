<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MobileTechnicianLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('login_name'))) {
            $this->merge(['login_name' => strtolower(trim($this->input('login_name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'login_name' => [
                'required', 'string', 'max:64',
                'regex:/^[a-z][a-z0-9._-]{2,63}$/D',
            ],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }
}
