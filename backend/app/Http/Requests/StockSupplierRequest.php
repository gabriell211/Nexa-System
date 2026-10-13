<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StockSupplierRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $tenant = $this->attributes->get('nexa_tenant');
        return [
            'code'=>['required','string','min:1','max:60',
                Rule::unique('stock_suppliers','code')->where('tenant_id', $tenant->id)],
            'name'=>['required','string','min:2','max:180'],
            'email'=>['sometimes','nullable','email','max:180'],
            'document'=>['sometimes','nullable','string','max:30'],
        ];
    }
}
