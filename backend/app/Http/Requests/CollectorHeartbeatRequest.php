<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CollectorHeartbeatRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'collector_id'=>['required','uuid'],
            'watchdog'=>['sometimes','array:recent_incidents,circuit_open'],
            'watchdog.recent_incidents'=>['required_with:watchdog','integer','between:0,32'],
            'watchdog.circuit_open'=>['required_with:watchdog','boolean'],
        ];
    }
}
