<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AccountInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->attributes->get('nexa_role'), ['owner', 'admin'], true);
    }

    public function rules(): array
    {
        $administrator = $this->input('account_type') === 'administrator';

        return [
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'email' => ['required', 'email', 'max:255'],
            'account_type' => ['required', Rule::in(['customer', 'collaborator', 'administrator'])],
            'customer_ids' => [
                Rule::requiredIf(! $administrator),
                Rule::prohibitedIf($administrator),
                'array', 'min:1', 'max:200',
            ],
            'customer_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'view_reports' => ['sometimes', 'boolean', Rule::prohibitedIf($administrator)],
            'view_other_tickets' => ['sometimes', 'boolean', Rule::prohibitedIf($administrator)],
        ];
    }
}
