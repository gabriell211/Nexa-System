<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ServiceTicketCommentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'body'=>['required','string','min:2','max:6000'],
            'internal'=>['required','boolean'],
        ];
    }
}
