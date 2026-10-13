<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Endpoint authorization is enforced by nexa.roles middleware.
    }

    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:2', 'max:180'],
            'document' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
