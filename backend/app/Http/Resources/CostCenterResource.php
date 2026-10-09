<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CostCenterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'customer_id' => $this->customer_id,
            'code' => $this->code, 'name' => $this->name, 'active' => $this->active,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
