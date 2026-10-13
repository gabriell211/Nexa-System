<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ServiceTicketRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'customer_id'=>['present','nullable','integer','min:1'],
            'printer_id'=>['present','nullable','integer','min:1'],
            'assigned_user_id'=>['sometimes','nullable','integer','min:1'],
            'subject'=>['required','string','min:4','max:180'],
            'description'=>['required','string','min:5','max:12000'],
            'category'=>['required','string','min:2','max:60'],
            'priority'=>['required',Rule::in(['low','normal','high','critical'])],
        ];
    }
}
