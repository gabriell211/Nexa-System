<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ServiceTicketTransitionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'status'=>['required',Rule::in(['triage','unassigned','pending','in_progress','resolved','closed'])],
            'reason'=>['required','string','min:5','max:500'],
            'assigned_user_id'=>['sometimes','nullable','integer','min:1'],
        ];
    }
}
