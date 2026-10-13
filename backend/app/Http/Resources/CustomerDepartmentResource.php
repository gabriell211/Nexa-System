<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CustomerDepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'customer_id' => $this->customer_id, 'location_id' => $this->location_id,
            'name' => $this->name, 'code' => $this->code,
            'responsible_name' => $this->responsible_name,
            'responsible_email' => $this->responsible_email,
            'active' => $this->active, 'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
