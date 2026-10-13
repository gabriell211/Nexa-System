<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StockItemRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $tenant = $this->attributes->get('nexa_tenant');
        $code = Rule::unique('stock_items', 'sku')->where('tenant_id', $tenant->id);
        $id = (int) ($this->route('item') ?? 0);
        if ($id > 0) $code->ignore($id);
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        return [
            'sku' => [$required, 'string', 'min:1', 'max:80', $code],
            'name' => [$required, 'string', 'min:2', 'max:180'],
            'category' => [$required, 'string', 'min:2', 'max:80'],
            'unit' => ['sometimes', 'string', 'min:1', 'max:16'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
